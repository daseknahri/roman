<?php
/**
 * Croșetăm site seed — run by docker/site-init.sh via `wp eval-file` on every start.
 *
 * Idempotent: the brand/site setup runs once per CR_SEED_VERSION, AdSense is re-applied every start, and each
 * article bundle in data/bundles is imported once (tracked in cr_imported_bundles), so a restart resumes.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

const CR_SEED_VERSION = 1;
const CR_DATA         = '/opt/site/data';
const CR_BRAND_DIR    = WP_CONTENT_DIR . '/themes/crosetam/assets/brand';

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

function cr_log( $msg ) { WP_CLI::log( '[seed] ' . $msg ); }

function cr_admin_id() {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	return $admins ? (int) $admins[0] : 1;
}

/* Copy a bundled brand file into the media library once (keyed by filename). */
function cr_brand_media( $file, $title ) {
	$found = get_posts( array( 'post_type' => 'attachment', 'meta_key' => '_cr_brand', 'meta_value' => $file, 'fields' => 'ids', 'numberposts' => 1 ) );
	if ( $found ) { return (int) $found[0]; }
	$tmp = wp_tempnam( $file );
	copy( CR_BRAND_DIR . '/' . $file, $tmp );
	$id = media_handle_sideload( array( 'name' => $file, 'tmp_name' => $tmp ), 0, $title );
	if ( is_wp_error( $id ) ) { cr_log( 'media failed: ' . $file . ' ' . $id->get_error_message() ); return 0; }
	update_post_meta( $id, '_cr_brand', $file );
	return (int) $id;
}

function cr_page( $slug, $title, $html ) {
	$p    = get_page_by_path( $slug );
	$data = array( 'post_type' => 'page', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $html, 'post_status' => 'publish', 'post_author' => cr_admin_id() );
	if ( $p ) { $data['ID'] = $p->ID; return (int) wp_update_post( wp_slash( $data ) ); }
	return (int) wp_insert_post( wp_slash( $data ) );
}

/* Category name => [slug, description]. Slugs are fixed so the category colours in the CSS match. */
function cr_categories() {
	return array(
		'Învață să croșetezi'      => array( 'invata-sa-crosetezi', 'Primii pași: cum ții croșeta, ochiurile de bază, cercul magic, cum citești o schemă și cum calculezi ochiurile.' ),
		'Casă și decor'            => array( 'casa-si-decor', 'Covoare, coșulețe, suporturi de pahar, flori și decorațiuni croșetate pentru casă și sărbători.' ),
		'Haine și accesorii'       => array( 'haine-si-accesorii', 'Căciuli, șosete, fuste, tunici și accesorii croșetate, explicate pas cu pas.' ),
		'Jucării și mărțișoare'    => array( 'jucarii-si-martisoare', 'Amigurumi, inimioare, fluturași și mărțișoare croșetate – proiecte mici, perfecte pentru cadouri.' ),
		'Pentru bebeluși și copii' => array( 'pentru-bebelusi-si-copii', 'Păturici, botoșei și pălării croșetate pentru cei mici, din fire moi și sigure.' ),
	);
}

function cr_seed_site() {
	update_option( 'blogname', 'Croșetăm' );
	update_option( 'blogdescription', 'Tutoriale de croșetat pas cu pas, în română' );
	update_option( 'timezone_string', 'Europe/Bucharest' );
	update_option( 'date_format', 'j F Y' );
	update_option( 'time_format', 'H:i' );
	update_option( 'start_of_week', 1 );
	update_option( 'posts_per_page', 12 );
	update_option( 'default_comment_status', 'closed' );
	update_option( 'default_ping_status', 'closed' );
	update_option( 'blog_public', 1 );
	update_option( 'permalink_structure', '/%postname%/' );

	/* Byline = the editorial team (no invented persona). */
	$admin = cr_admin_id();
	wp_update_user( array( 'ID' => $admin, 'display_name' => 'Redacția Croșetăm', 'nickname' => 'Redacția Croșetăm', 'first_name' => 'Redacția', 'last_name' => 'Croșetăm',
		'description' => 'Echipa Croșetăm alege cele mai clare tutoriale video de croșetat în limba română și le transformă în ghiduri scrise, pas cu pas, cu materiale, sfaturi și greșeli de evitat.' ) );
	set_theme_mod( 'vr_noun', 'article' );

	$icon = cr_brand_media( 'site-icon-512.png', 'Croșetăm – iconiță' );
	if ( $icon ) { update_option( 'site_icon', $icon ); update_user_meta( $admin, 'vr_author_avatar', $icon ); }
	$logo = cr_brand_media( 'logo.png', 'Croșetăm – logo' );
	if ( $logo ) { set_theme_mod( 'custom_logo', $logo ); update_option( 'site_logo', $logo ); }

	foreach ( array( array( 'hello-world', 'post' ), array( 'sample-page', 'page' ) ) as $x ) {
		$p = get_page_by_path( $x[0], OBJECT, $x[1] );
		if ( $p ) { wp_delete_post( $p->ID, true ); }
	}

	foreach ( cr_categories() as $name => $c ) {
		$t = get_term_by( 'slug', $c[0], 'category' );
		if ( ! $t ) { $t = get_term_by( 'name', $name, 'category' ); }
		if ( $t ) { wp_update_term( $t->term_id, 'category', array( 'name' => $name, 'slug' => $c[0], 'description' => $c[1] ) ); }
		else { wp_insert_term( $name, 'category', array( 'slug' => $c[0], 'description' => $c[1] ) ); }
	}
	$uncat = get_term_by( 'slug', 'uncategorized', 'category' );
	if ( $uncat ) { wp_update_term( $uncat->term_id, 'category', array( 'name' => 'Diverse', 'slug' => 'diverse' ) ); }

	/* ---- Pages ---- */
	cr_page( 'despre-noi', 'Despre noi',
		'<p><strong>Croșetăm</strong> este un loc pentru toți cei care vor să învețe să croșeteze sau să încerce un model nou – în limba română, pas cu pas.</p>'
		. '<p>Pe YouTube există tutoriale românești excelente, dar un video nu-ți spune întotdeauna ce fir să cumperi, ce croșetă să alegi sau ce greșeli să eviți. De aceea, pentru fiecare tutorial pe care îl recomandăm scriem un ghid complet: materialele necesare, pașii explicați pe înțeles, sfaturi practice, variante și răspunsuri la întrebările frecvente.</p>'
		. '<h2>Cum alegem tutorialele</h2><p>Alegem videoclipuri în limba română, clare și ușor de urmărit, de la creatoare și creatori care își explică bine lucrul. Videoclipurile rămân ale autorilor lor: le includem în pagină prin playerul oficial YouTube și trimitem mereu către canalul original. Dacă îți place un tutorial, abonează-te la canalul autorului – așa îi susții direct.</p>'
		. '<h2>Pentru cine scriem</h2><p>Pentru începătoare care țin croșeta în mână prima dată, dar și pentru cele care caută idei noi: căciuli, păturici, flori, jucării amigurumi, mărțișoare și decorațiuni de sărbători.</p>'
		. '<p>Ai o întrebare sau o sugestie de tutorial? <a href="/contact/">Scrie-ne</a>.</p>' );
	cr_page( 'contact', 'Contact',
		'<p>Ne bucurăm să primim mesaje de la cititori: întrebări despre un model, sugestii de tutoriale sau semnalarea unei greșeli.</p>'
		. '<p>E-mail: <a href="mailto:daseknahri@gmail.com">daseknahri@gmail.com</a></p>'
		. '<p>Ești autorul unui videoclip prezentat pe site și vrei să modificăm ceva? Scrie-ne și răspundem cât de repede putem.</p>' );
	$privacy = cr_page( 'politica-de-confidentialitate', 'Politica de confidențialitate',
		'<p>Această politică explică ce date sunt prelucrate atunci când vizitezi site-ul Croșetăm (crosetam.getemoji.site).</p>'
		. '<h2>1. Operatorul</h2><p>Site-ul este administrat de Dasek Nahri, Boukhalef, 90090 Tanger, Maroc. Contact: <a href="mailto:daseknahri@gmail.com">daseknahri@gmail.com</a>.</p>'
		. '<h2>2. Date tehnice și jurnale de server</h2><p>La accesarea site-ului, serverul înregistrează automat date tehnice necesare funcționării în siguranță (adresa IP, data și ora, pagina accesată, browserul). Temei: interesul legitim de a asigura funcționarea și securitatea site-ului (art. 6 alin. 1 lit. f GDPR).</p>'
		. '<h2>3. Fonturi</h2><p>Fonturile sunt încărcate de pe serverul nostru; nu se face nicio conexiune către Google Fonts.</p>'
		. '<h2>4. Publicitate (Google AdSense) și consimțământ</h2><p>Site-ul este finanțat prin reclame afișate de Google AdSense (Google Ireland Ltd., Gordon House, Barrow Street, Dublin 4, Irlanda). Google poate folosi cookie-uri și tehnologii similare pentru a afișa și măsura reclamele. Reclamele personalizate sunt afișate doar cu consimțământul tău (art. 6 alin. 1 lit. a GDPR), pe care îl poți acorda, refuza sau retrage oricând prin bannerul de consimțământ. Detalii: <a href="https://policies.google.com/technologies/ads?hl=ro" rel="nofollow noopener">policies.google.com/technologies/ads</a>.</p>'
		. '<h2>5. Videoclipuri YouTube</h2><p>Tutorialele video sunt incluse prin playerul YouTube în modul cu confidențialitate sporită (youtube-nocookie.com). Până când apeși pe butonul de redare, pagina afișează doar o imagine și nu se încarcă playerul. După ce pornești videoclipul, YouTube (Google) poate prelucra date conform propriei politici: <a href="https://policies.google.com/privacy?hl=ro" rel="nofollow noopener">policies.google.com/privacy</a>.</p>'
		. '<h2>6. Butoanele de distribuire</h2><p>Butoanele „Distribuie pe Facebook”, „Trimite pe WhatsApp” și „Salvează pe Pinterest” sunt simple linkuri. Nu se transmit date către aceste rețele decât dacă dai clic pe ele.</p>'
		. '<h2>7. Drepturile tale</h2><p>Ai dreptul de acces, rectificare, ștergere, restricționare, portabilitate și opoziție, precum și dreptul de a depune o plângere la Autoritatea Națională de Supraveghere a Prelucrării Datelor cu Caracter Personal (ANSPDCP, <a href="https://www.dataprotection.ro" rel="nofollow noopener">dataprotection.ro</a>).</p>' );
	update_option( 'wp_page_for_privacy_policy', $privacy );
	cr_page( 'termeni-si-conditii', 'Termeni și condiții',
		'<h2>Folosirea conținutului</h2><p>Textele, fotografiile de prezentare și elementele grafice ale site-ului sunt protejate. Poți distribui liber linkurile către articole; reproducerea integrală a textelor fără acord nu este permisă.</p>'
		. '<h2>Videoclipurile</h2><p>Videoclipurile aparțin autorilor lor și sunt afișate prin funcția oficială de încorporare a YouTube, cu link către canalul original. Fotografiile de prezentare provin de pe Pexels și sunt folosite conform licenței Pexels.</p>'
		. '<h2>Fără garanții</h2><p>Tutorialele au scop informativ. Cantitățile de fir, dimensiunile și numărul de ochiuri sunt orientative și pot varia în funcție de fir, croșetă și tensiunea fiecăruia – fă întotdeauna o probă.</p>'
		. '<h2>Linkuri externe</h2><p>Nu răspundem pentru conținutul site-urilor externe către care trimitem.</p>' );
	cr_page( 'politica-editoriala', 'Politica editorială',
		'<p>Fiecare articol de pe Croșetăm pornește de la un tutorial video în limba română, ales pentru claritate. Textul îl scriem noi: explicăm materialele, tehnica și pașii cu propriile cuvinte, adăugăm sfaturi, greșeli frecvente și variante.</p>'
		. '<p>Când videoclipul nu precizează o cifră (mărimea croșetei, numărul de ochiuri), oferim recomandări generale și spunem acest lucru – nu inventăm date atribuite autorului.</p>'
		. '<p>Creditul pentru fiecare video merge la autorul lui, cu link către canal. Dacă găsești o greșeală, <a href="/contact/">scrie-ne</a> și o corectăm.</p>' );

	/* ---- Menus ---- */
	$menu = wp_get_nav_menu_object( 'Meniu principal' );
	$mid  = $menu ? $menu->term_id : wp_create_nav_menu( 'Meniu principal' );
	if ( ! $menu ) {
		foreach ( cr_categories() as $c ) {
			$t = get_term_by( 'slug', $c[0], 'category' );
			if ( $t ) { wp_update_nav_menu_item( $mid, 0, array( 'menu-item-object' => 'category', 'menu-item-object-id' => $t->term_id, 'menu-item-type' => 'taxonomy', 'menu-item-status' => 'publish' ) ); }
		}
	}
	$fmenu = wp_get_nav_menu_object( 'Subsol' );
	$fid   = $fmenu ? $fmenu->term_id : wp_create_nav_menu( 'Subsol' );
	if ( ! $fmenu ) {
		foreach ( array( 'despre-noi', 'politica-editoriala', 'contact', 'politica-de-confidentialitate', 'termeni-si-conditii' ) as $slug ) {
			$p = get_page_by_path( $slug );
			if ( $p ) { wp_update_nav_menu_item( $fid, 0, array( 'menu-item-object' => 'page', 'menu-item-object-id' => $p->ID, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) ); }
		}
	}
	$locs = get_theme_mod( 'nav_menu_locations', array() );
	foreach ( array_keys( get_registered_nav_menus() ) as $loc ) {
		if ( false !== strpos( $loc, 'primary' ) || 'menu-1' === $loc ) { $locs[ $loc ] = $mid; }
		if ( false !== strpos( $loc, 'footer' ) ) { $locs[ $loc ] = $fid; }
	}
	set_theme_mod( 'nav_menu_locations', $locs );

	/* Default widgets (English "Recent Posts", Archives, Meta) out; the theme renders its own blocks. */
	$sw = (array) get_option( 'sidebars_widgets', array() );
	foreach ( $sw as $k => $v ) { if ( is_array( $v ) && 'wp_inactive_widgets' !== $k ) { $sw[ $k ] = array(); } }
	update_option( 'sidebars_widgets', $sw );

	flush_rewrite_rules( false );
	update_option( 'cr_seed_version', CR_SEED_VERSION );
	cr_log( 'site seeded' );
}

/* AdSense Auto ads through the plugin (getemoji.site's publisher; the AdSense site entry covers subdomains).
   Applied every start; ADSENSE_CLIENT=off disables, empty falls back to the default. */
const CR_ADSENSE_DEFAULT = 'ca-pub-6869205417923902';
function cr_apply_ads() {
	$client = trim( (string) getenv( 'ADSENSE_CLIENT' ) );
	if ( '' === $client ) { $client = CR_ADSENSE_DEFAULT; }
	$ads = (array) get_option( 'wpap_ads_inject', array() );
	if ( 'off' === $client || ! preg_match( '/^ca-pub-\d{10,20}$/', $client ) ) {
		$ads['enabled'] = 0;
		update_option( 'wpap_ads_inject', $ads );
		return;
	}
	$ads['enabled']   = 1;
	$ads['scope_all'] = 1;
	$ads['auto_code'] = '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . $client . '" crossorigin="anonymous"></script>';
	$ads['slots']     = array();
	$ads['zones']     = array();
	$ads['label']     = 0;
	update_option( 'wpap_ads_inject', $ads );
	update_option( 'wpap_ads_txt', 'google.com, ' . str_replace( 'ca-', '', $client ) . ', DIRECT, f08c47fec0942fa0' );
}

/* Facebook Page auto-posting (plugin ≥ 9.43): configured from Coolify env so nobody has to type the token into
   wp-admin. FB_PAGE_ID = the Page's Graph id; FB_PAGE_TOKEN = a (long-lived) user or Page token — a user token is
   swapped for the Page's own token. A new token is only exchanged when it changes (hash kept, never the token).
   FB_POSTS_PER_DAY (default 4) are shared between 9:00 and 21:00 site time, photo + hook, link in the first comment.
   FB_PAGE_ID=off switches posting off. Without env values the wp-admin settings are left untouched. */
function cr_apply_facebook() {
	if ( ! function_exists( 'wpap_fbp_opts' ) ) { return; }
	$page_id = trim( (string) getenv( 'FB_PAGE_ID' ) );
	$token   = preg_replace( '/[^A-Za-z0-9]/', '', (string) getenv( 'FB_PAGE_TOKEN' ) );
	$raw     = (array) get_option( 'wpap_fbpage', array() );
	if ( 'off' === $page_id ) {
		$raw['enabled'] = 0;
		update_option( 'wpap_fbpage', $raw, false );
		wpap_fbp_schedule( wpap_fbp_cron_wanted() );
		return;
	}
	if ( ! ctype_digit( $page_id ) ) { return; }
	$per_day = (int) getenv( 'FB_POSTS_PER_DAY' );
	$raw = array_merge( array( 'since' => '2026-10-04', 'backlog' => 1, 'format' => 'photo', 'start' => 9, 'end' => 21 ), $raw, array(
		'enabled' => 1,
		'page_id' => $page_id,
		'per_day' => $per_day > 0 ? $per_day : (int) ( $raw['per_day'] ?? 4 ),
	) );
	$hash = '' !== $token ? hash( 'sha256', $page_id . '|' . $token ) : '';
	if ( '' !== $hash && get_option( 'cr_fb_token_hash' ) !== $hash ) {
		$raw['token'] = wpap_fbp_page_token( $page_id, $token );
		update_option( 'cr_fb_token_hash', $hash, false );
		delete_option( 'wpap_fbp_paused' );
		delete_transient( 'wpap_fbp_backoff' );
		cr_log( 'facebook: token updated for Page ' . $page_id . ' (' . (string) get_option( 'wpap_fbp_page_name' ) . ')' );
	}
	update_option( 'wpap_fbpage', $raw, false );
	wpap_fbp_schedule( wpap_fbp_cron_wanted() );
}

function cr_rmtree( $dir ) {
	if ( ! is_dir( $dir ) ) { return; }
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) { $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); }
	rmdir( $dir );
}

/* Publish one bundle item; returns the post id or 0. Pins the planned slug (internal-link tokens use it). */
function cr_import_item( array $item, $work, $admin ) {
	$slug = sanitize_title( $item['slug'] ?? '' );
	if ( '' === $slug ) { return 0; }
	if ( get_page_by_path( $slug, OBJECT, 'post' ) ) { return 0; }   /* resume-safe */
	$opts = array( 'default_parts' => 1, 'schedule_window' => 0, 'author' => $admin );
	$img  = isset( $item['image'] ) ? realpath( $work . '/' . ltrim( (string) $item['image'], '/' ) ) : false;
	if ( $img && 0 === strpos( $img, realpath( $work ) ) ) { $opts['local_image_path'] = $img; }
	$id = wpap_publish_article( $item, $opts );
	if ( is_wp_error( $id ) || ! $id ) { cr_log( 'skip ' . $slug . ': ' . ( is_wp_error( $id ) ? $id->get_error_message() : 'no id' ) ); return 0; }
	$id = (int) $id;
	wp_update_post( array( 'ID' => $id, 'post_name' => $slug ) );
	$v = (array) ( $item['video'] ?? array() );
	$meta = array(
		'_cr_video_id'          => $v['id'] ?? '',
		'_cr_video_title'       => $v['title'] ?? '',
		'_cr_video_channel'     => $v['channel'] ?? '',
		'_cr_video_channel_url' => $v['channelUrl'] ?? '',
		'_cr_video_date'        => $v['date'] ?? '',
		'_cr_level'             => $item['level'] ?? '',
		'_cr_time'              => $item['time'] ?? '',
		'_cr_pin_title'         => $item['pin_title'] ?? '',
		'_cr_pin_description'   => $item['pin_description'] ?? '',
	);
	foreach ( $meta as $k => $val ) { if ( '' !== (string) $val ) { update_post_meta( $id, $k, sanitize_text_field( (string) $val ) ); } }
	return $id;
}

function cr_import_bundles() {
	if ( ! function_exists( 'wpap_publish_article' ) ) { cr_log( 'plugin not loaded — skipping import' ); return; }
	$done  = (array) get_option( 'cr_imported_bundles', array() );
	$zips  = glob( CR_DATA . '/bundles/*.zip' ) ?: array();
	sort( $zips );
	$admin = cr_admin_id();
	wp_set_current_user( $admin );
	$new = 0;
	foreach ( $zips as $zip ) {
		$name = basename( $zip );
		if ( in_array( $name, $done, true ) ) { continue; }
		$work = trailingslashit( get_temp_dir() ) . 'cr-' . sanitize_file_name( $name );
		cr_rmtree( $work );
		$z = new ZipArchive();
		if ( true !== $z->open( $zip ) ) { cr_log( "cannot open $name" ); continue; }
		$z->extractTo( $work );
		$z->close();
		$items = json_decode( (string) file_get_contents( $work . '/posts.json' ), true );
		$n = 0;
		foreach ( (array) $items as $item ) { if ( cr_import_item( (array) $item, $work, $admin ) ) { $n++; } }
		cr_rmtree( $work );
		$done[] = $name;
		update_option( 'cr_imported_bundles', $done, false );
		cr_log( "$name: $n articles imported" );
		$new += $n;
	}
	if ( $new && function_exists( 'wpap_internal_links_bake' ) ) {
		$r = wpap_internal_links_bake( 500 );
		cr_log( 'internal links: ' . wp_json_encode( $r ) );
	}
}

if ( (int) get_option( 'cr_seed_version', 0 ) < CR_SEED_VERSION ) { cr_seed_site(); }
cr_apply_ads();
cr_import_bundles();
cr_apply_facebook();
