<?php
/**
 * Croșetăm — child theme of Viral Reader for a Romanian crochet-tutorial site built around YouTube videos.
 *
 * Presentation only: brand CSS, a "tutorial card" (level / time / video creator) above each article, a fast
 * click-to-play YouTube embed, VideoObject schema for the embedded tutorial, an end-of-article share + "next
 * tutorial" block, a sticky next-tutorial bar and brand social cards for non-article pages.
 *
 * @package Crosetam
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'CR_VERSION', '1.0.0' );

function cr_asset( $rel ) {
	return get_stylesheet_directory_uri() . '/assets/' . ltrim( $rel, '/' );
}

/* ---------- Styles ----------
   The parent inlines get_stylesheet_directory()/style.css, which for a child theme is only the child's header.
   So inline the parent stylesheet first, then the brand layer (no render-blocking CSS file). */
add_action( 'wp_enqueue_scripts', function () {
	$parent = get_template_directory() . '/style.css';
	$brand  = get_stylesheet_directory() . '/assets/crosetam.css';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled theme files.
	$css = ( is_readable( $parent ) ? file_get_contents( $parent ) : '' ) . "\n" . ( is_readable( $brand ) ? file_get_contents( $brand ) : '' );
	$css = str_replace( '__THEME__', get_stylesheet_directory_uri(), $css );
	wp_add_inline_style( 'viral-reader', $css );
}, 11 );

/* Headings use Fraunces: preload the Latin-extended file (ă ș ț î â live there). */
add_action( 'wp_head', function () {
	printf( '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url( cr_asset( 'fonts/fraunces-600-ext.woff2' ) ) );
}, 2 );

/* ---------- Parent feature switches ---------- */
add_filter( 'vr_enable_share_at_end', '__return_false' );   /* replaced by the end block below */
add_filter( 'vr_site_icon_svg_url', function () { return cr_asset( 'brand/mark.svg' ); } );
add_filter( 'vr_hero_image_url', function () {
	return is_readable( get_stylesheet_directory() . '/assets/brand/hero-1280.webp' ) ? cr_asset( 'brand/hero-1280.webp' ) : '';
} );
add_filter( 'vr_pin_desc_suffix', function () { return 'Croșetăm – tutoriale de croșetat în română'; } );

/* Home: the hero image is the brand photo, so preload it instead of the newest post's cover. */
add_action( 'wp_head', function () {
	if ( ! is_front_page() || is_paged() || '' === apply_filters( 'vr_hero_image_url', '' ) ) { return; }
	remove_action( 'wp_head', 'vr_preload_lcp', 1 );
	printf( '<link rel="preload" as="image" href="%s" type="image/webp" fetchpriority="high">' . "\n", esc_url( cr_asset( 'brand/hero-1280.webp' ) ) );
}, 0 );

/* Category tiles on the home page use the newest post image of that category. */
add_filter( 'vr_category_cover_url', function ( $url, $term_id ) {
	if ( '' !== $url || ! $term_id ) { return $url; }
	$cache = get_transient( 'cr_cover_' . $term_id );
	if ( false !== $cache ) { return $cache; }
	$q   = get_posts( array( 'cat' => (int) $term_id, 'numberposts' => 1, 'meta_key' => '_thumbnail_id', 'fields' => 'ids' ) );
	$img = $q ? (string) get_the_post_thumbnail_url( $q[0], 'medium_large' ) : '';
	set_transient( 'cr_cover_' . $term_id, $img, DAY_IN_SECONDS );
	return $img;
}, 10, 2 );

/* ---------- The embedded tutorial ----------
   The seed stores the YouTube id + creator on each post (_cr_video_*). */
function cr_video( $post_id ) {
	$id = (string) get_post_meta( $post_id, '_cr_video_id', true );
	if ( ! preg_match( '/^[A-Za-z0-9_-]{11}$/', $id ) ) { return null; }
	return array(
		'id'      => $id,
		'title'   => (string) get_post_meta( $post_id, '_cr_video_title', true ),
		'channel' => (string) get_post_meta( $post_id, '_cr_video_channel', true ),
		'url'     => (string) get_post_meta( $post_id, '_cr_video_channel_url', true ),
		'date'    => (string) get_post_meta( $post_id, '_cr_video_date', true ),
	);
}

/* Click-to-play facade: a phone loads one thumbnail instead of ~1 MB of YouTube player until the reader taps play. */
function cr_video_facade( $id, $title ) {
	$label = '' !== $title ? $title : 'Tutorial video';
	return '<div class="cr-video" data-id="' . esc_attr( $id ) . '">'
		. '<button type="button" class="cr-video__btn" aria-label="' . esc_attr( 'Pornește videoclipul: ' . $label ) . '">'
		. '<img src="https://i.ytimg.com/vi/' . esc_attr( $id ) . '/hqdefault.jpg" alt="" data-no-pin width="480" height="360" loading="lazy" decoding="async">'
		. '<span class="cr-video__play" aria-hidden="true"><svg viewBox="0 0 68 48" width="68" height="48"><path d="M66.5 7.7a8.6 8.6 0 0 0-6-6C55.2.3 34 .3 34 .3s-21.2 0-26.5 1.4a8.6 8.6 0 0 0-6 6C.1 13 .1 24 .1 24s0 11 1.4 16.3a8.6 8.6 0 0 0 6 6C12.8 47.7 34 47.7 34 47.7s21.2 0 26.5-1.4a8.6 8.6 0 0 0 6-6C67.9 35 67.9 24 67.9 24s0-11-1.4-16.3z" fill="#B5403A"/><path d="M45 24 27 14v20" fill="#fff"/></svg></span>'
		. '</button></div>';
}

add_filter( 'embed_oembed_html', function ( $html, $url ) {
	if ( is_admin() || ! preg_match( '~(?:youtube\.com/(?:watch\?v=|embed/)|youtu\.be/)([A-Za-z0-9_-]{11})~', (string) $url, $m ) ) { return $html; }
	$title = preg_match( '/title="([^"]*)"/', (string) $html, $t ) ? html_entity_decode( $t[1] ) : '';
	return cr_video_facade( $m[1], $title );
}, 10, 2 );

add_action( 'wp_footer', function () {
	if ( ! is_singular( 'post' ) ) { return; }
	?>
	<script>
	document.addEventListener('click',function(e){var b=e.target.closest('.cr-video__btn');if(!b)return;var w=b.parentNode,f=document.createElement('iframe');
	f.src='https://www.youtube-nocookie.com/embed/'+w.getAttribute('data-id')+'?autoplay=1&rel=0';f.title=b.getAttribute('aria-label');f.allow='accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; web-share';f.allowFullscreen=true;f.setAttribute('referrerpolicy','strict-origin-when-cross-origin');w.replaceChildren(f);w.classList.add('is-playing');});
	</script>
	<?php
}, 20 );

/* VideoObject for the embedded tutorial (video rich results). Needs an upload date, so only when we have one. */
add_action( 'wp_head', function () {
	if ( ! is_singular( 'post' ) ) { return; }
	$post_id = get_queried_object_id();
	$v       = cr_video( $post_id );
	if ( ! $v || '' === $v['date'] ) { return; }
	$schema = array(
		'@context'     => 'https://schema.org',
		'@type'        => 'VideoObject',
		'name'         => '' !== $v['title'] ? $v['title'] : get_the_title( $post_id ),
		'description'  => wp_strip_all_tags( get_the_excerpt( $post_id ) ),
		'thumbnailUrl' => array( 'https://i.ytimg.com/vi/' . $v['id'] . '/hqdefault.jpg' ),
		'uploadDate'   => $v['date'],
		'embedUrl'     => 'https://www.youtube.com/embed/' . $v['id'],
		'contentUrl'   => 'https://www.youtube.com/watch?v=' . $v['id'],
	);
	if ( '' !== $v['channel'] ) { $schema['author'] = array( '@type' => 'Person', 'name' => $v['channel'], 'url' => $v['url'] ); }
	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}, 20 );

/* ---------- Tutorial card above the article: level, time, video creator ---------- */
add_filter( 'the_content', function ( $content ) {
	if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) { return $content; }
	$id    = get_the_ID();
	$level = trim( (string) get_post_meta( $id, '_cr_level', true ) );
	$time  = trim( (string) get_post_meta( $id, '_cr_time', true ) );
	$v     = cr_video( $id );
	if ( '' === $level && '' === $time && ! $v ) { return $content; }
	$out = '<ul class="cr-card" aria-label="Despre acest tutorial">';
	if ( '' !== $level ) { $out .= '<li><span>Nivel</span><strong>' . esc_html( $level ) . '</strong></li>'; }
	if ( '' !== $time ) { $out .= '<li><span>Timp</span><strong>' . esc_html( $time ) . '</strong></li>'; }
	if ( $v && '' !== $v['channel'] ) { $out .= '<li><span>Video</span><strong>' . esc_html( $v['channel'] ) . '</strong></li>'; }
	$out .= '</ul>';
	return $out . $content;
}, 8 );

/* ---------- The next tutorial ----------
   Same category first, newest older post; wraps to the newest of the category, then any recent post. */
function cr_next_post( $post_id ) {
	$key  = 'cr_next_' . $post_id;
	$next = get_transient( $key );
	if ( false !== $next ) { return $next ? get_post( $next ) : null; }
	$cats = wp_get_post_categories( $post_id );
	$base = array( 'numberposts' => 1, 'post__not_in' => array( $post_id ), 'fields' => 'ids', 'ignore_sticky_posts' => true );
	$date = get_post_field( 'post_date', $post_id );
	$try  = array();
	if ( $cats ) {
		$try[] = $base + array( 'category__in' => $cats, 'date_query' => array( array( 'before' => $date ) ) );
		$try[] = $base + array( 'category__in' => $cats );
	}
	$try[] = $base;
	$id = 0;
	foreach ( $try as $args ) {
		$found = get_posts( $args );
		if ( $found ) { $id = (int) $found[0]; break; }
	}
	set_transient( $key, $id, HOUR_IN_SECONDS );
	return $id ? get_post( $id ) : null;
}

function cr_icon( $name ) {
	$icons = array(
		'fb'    => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13.5 21v-7.5h2.6l.4-3h-3V8.6c0-.9.3-1.5 1.6-1.5h1.6V4.4c-.3 0-1.2-.1-2.3-.1-2.3 0-3.9 1.4-3.9 4v2.2H7.9v3h2.6V21h3z"/></svg>',
		'wa'    => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3zm4.6 12.6c-.2.6-1.1 1.1-1.6 1.2-.4.1-1 .1-1.6-.1-.4-.1-.9-.3-1.5-.6-2.6-1.1-4.3-3.8-4.4-4-.1-.2-1-1.4-1-2.6s.6-1.9.9-2.1c.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.3 0 .5l-.3.5-.4.4c-.1.1-.3.3-.1.6.2.3.7 1.2 1.6 1.9 1.1.9 2 1.2 2.3 1.4.3.1.4.1.6-.1l.8-1c.2-.3.4-.2.6-.1l1.9.9c.3.1.5.2.5.3.1.2.1.7-.1 1.3z"/></svg>',
		'pin'   => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-3.6 19.3c-.1-.8-.2-2 0-2.9l1.2-5s-.3-.6-.3-1.5c0-1.4.8-2.4 1.8-2.4.9 0 1.3.6 1.3 1.4 0 .9-.5 2.1-.8 3.3-.2 1 .5 1.8 1.5 1.8 1.8 0 3.1-1.9 3.1-4.6 0-2.4-1.7-4.1-4.2-4.1-2.9 0-4.5 2.1-4.5 4.4 0 .9.3 1.8.8 2.3l.1.4-.3 1.1c0 .2-.2.2-.4.1-1.3-.6-2.1-2.5-2.1-4 0-3.3 2.4-6.3 6.9-6.3 3.6 0 6.4 2.6 6.4 6 0 3.6-2.3 6.5-5.4 6.5-1.1 0-2.1-.6-2.4-1.2l-.7 2.5c-.2.9-.9 2.1-1.3 2.8A10 10 0 1 0 12 2z"/></svg>',
		'arrow' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
	);
	return $icons[ $name ] ?? '';
}

/* ---------- End of article: share + next tutorial ----------
   Priority 30: after the plugin's in-content ads (15) so the block always closes the article. */
add_filter( 'the_content', function ( $content ) {
	if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) { return $content; }
	$id    = get_the_ID();
	$url   = rawurlencode( get_permalink( $id ) );
	$img   = rawurlencode( (string) get_the_post_thumbnail_url( $id, 'large' ) );
	$title = rawurlencode( get_the_title( $id ) );

	$out  = '<aside class="cr-end" aria-label="Distribuie tutorialul">';
	$out .= '<img class="cr-end__mark" src="' . esc_url( cr_asset( 'brand/mark.svg' ) ) . '" alt="" width="44" height="44">';
	$out .= '<p class="cr-end__q">Ți-a plăcut modelul? Trimite-l unei prietene care croșetează.</p>';
	$out .= '<div class="cr-end__actions">';
	$out .= '<a class="cr-btn cr-btn--fb" href="https://www.facebook.com/sharer/sharer.php?u=' . $url . '" target="_blank" rel="noopener nofollow">' . cr_icon( 'fb' ) . 'Distribuie pe Facebook</a>';
	$out .= '<a class="cr-btn cr-btn--wa" href="https://wa.me/?text=' . $url . '" target="_blank" rel="noopener nofollow">' . cr_icon( 'wa' ) . 'Trimite pe WhatsApp</a>';
	$out .= '<a class="cr-btn cr-btn--pin" href="https://www.pinterest.com/pin/create/button/?url=' . $url . '&amp;media=' . $img . '&amp;description=' . $title . '" target="_blank" rel="noopener nofollow">' . cr_icon( 'pin' ) . 'Salvează pe Pinterest</a>';
	$out .= '</div></aside>';

	$next = cr_next_post( $id );
	if ( $next ) {
		$out  .= '<a class="cr-next" href="' . esc_url( get_permalink( $next ) ) . '">';
		$thumb = get_the_post_thumbnail_url( $next, 'thumbnail' );
		if ( $thumb ) { $out .= '<img src="' . esc_url( $thumb ) . '" alt="" width="120" height="120" loading="lazy">'; }
		$out .= '<span><span class="cr-next__eyebrow">Următorul tutorial ' . cr_icon( 'arrow' ) . '</span><span class="cr-next__title">' . esc_html( get_the_title( $next ) ) . '</span></span></a>';
	}
	return $content . $out;
}, 30 );

/* ---------- Sticky next-tutorial bar (phones + desktop corner), shown after 60% of the article ---------- */
add_action( 'wp_footer', function () {
	if ( ! is_singular( 'post' ) ) { return; }
	$next = cr_next_post( get_queried_object_id() );
	if ( ! $next ) { return; }
	$thumb = get_the_post_thumbnail_url( $next, 'thumbnail' );
	?>
	<a class="cr-bar" id="cr-bar" href="<?php echo esc_url( get_permalink( $next ) ); ?>" aria-hidden="true" tabindex="-1">
		<?php if ( $thumb ) : ?><img src="<?php echo esc_url( $thumb ); ?>" alt="" width="48" height="48" loading="lazy"><?php endif; ?>
		<span class="cr-bar__t"><b>Următorul tutorial</b><span><?php echo esc_html( get_the_title( $next ) ); ?></span></span>
		<span class="cr-bar__go"><?php echo cr_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG ?></span>
		<button class="cr-bar__x" type="button" aria-label="Închide">&times;</button>
	</a>
	<script>
	(function(){
		var bar=document.getElementById('cr-bar'),src=document.querySelector('.entry-content');
		if(!bar||!src)return;
		try{if(sessionStorage.getItem('crBarOff'))return;}catch(e){}
		bar.querySelector('.cr-bar__x').addEventListener('click',function(e){e.preventDefault();e.stopPropagation();bar.classList.remove('is-on');try{sessionStorage.setItem('crBarOff','1');}catch(x){}});
		var tick=false,update=function(){var r=src.getBoundingClientRect(),seen=(innerHeight-r.top)/r.height;var on=seen>.6;bar.classList.toggle('is-on',on);bar.setAttribute('aria-hidden',on?'false':'true');bar.tabIndex=on?0:-1;tick=false;};
		addEventListener('scroll',function(){if(!tick){tick=true;requestAnimationFrame(update);}},{passive:true});
		/* Lift the bar above Google's bottom anchor ad so neither hides the other. */
		setInterval(function(){var a=document.querySelector('ins.adsbygoogle[data-anchor-status="displayed"],ins.adsbygoogle-noablate[data-anchor-status="displayed"]'),h=0;if(a){var r=a.getBoundingClientRect();if(r.bottom>=innerHeight-2&&r.height<200){h=Math.round(r.height);}}document.documentElement.style.setProperty('--cr-anchor',h+'px');},1500);
	})();
	</script>
	<?php
}, 30 );

/* ---------- Social cards for the home page, categories and pages ----------
   Articles get their own og: tags from the plugin; everything else falls back to the brand card. */
add_action( 'wp_head', function () {
	if ( is_singular( 'post' ) ) { return; }
	$title = is_front_page() ? get_bloginfo( 'name' ) . ' – ' . get_bloginfo( 'description' ) : wp_get_document_title();
	$desc  = is_category() ? wp_strip_all_tags( category_description() ) : '';
	if ( '' === $desc ) { $desc = 'Tutoriale de croșetat pas cu pas, în limba română: ochiuri de bază, căciuli, păturici, flori, jucării amigurumi și decorațiuni, cu video și explicații clare.'; }
	$url = is_front_page() ? home_url( '/' ) : ( is_category() ? get_category_link( get_queried_object_id() ) : get_permalink() );
	echo '<meta property="og:type" content="website">' . "\n";
	echo '<meta property="og:locale" content="ro_RO">' . "\n";
	echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
	echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
	echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
	echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
	echo '<meta property="og:image" content="' . esc_url( cr_asset( 'brand/og.jpg' ) ) . '">' . "\n";
	echo '<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">' . "\n";
	echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
	if ( is_front_page() || is_category() ) { echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n"; }
}, 5 );

/* Site Kit's AdSense snippet would load adsbygoogle.js a second time; the plugin already prints the one tag. */
add_filter( 'googlesitekit_adsense_tag_blocked', function ( $blocked ) {
	$ads = function_exists( 'wpap_get_ads' ) ? wpap_get_ads() : array();
	return ! empty( $ads['enabled'] ) ? true : $blocked;
} );
