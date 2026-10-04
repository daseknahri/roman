<?php
/**
 * Header.
 *
 * @package Viral_Reader
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<?php if ( is_singular( 'post' ) ) : ?><div class="vr-progress" id="vr-progress" aria-hidden="true"></div><?php endif; ?>

<a class="vr-skip" href="#content"><?php esc_html_e( 'Skip to content', 'viral-reader' ); ?></a>

<header class="site-header">
	<div class="vr-container site-header__inner">
		<div class="site-brand">
			<?php
			if ( has_custom_logo() ) {
				the_custom_logo();
			} else {
				printf(
					'<a class="site-brand__name" href="%s" rel="home">%s</a>',
					esc_url( home_url( '/' ) ),
					esc_html( get_bloginfo( 'name' ) )
				);
			}
			?>
		</div>

		<nav class="site-nav" id="site-nav" aria-label="<?php esc_attr_e( 'Primary', 'viral-reader' ); ?>">
			<?php
			wp_nav_menu( array(
				'theme_location' => 'primary',
				'container'      => false,
				'fallback_cb'    => 'vr_fallback_menu',
				'depth'          => (int) apply_filters( 'vr_nav_depth', 3 ),   /* 1.9.31: dropdowns; 3 = mega-menu columns */
			) );
			?>
		</nav>

		<div class="header-tools">
			<div class="header-search">
				<a class="header-icon" href="<?php echo esc_url( home_url( '/?s=' ) ); ?>" aria-label="<?php esc_attr_e( 'Search', 'viral-reader' ); ?>" aria-controls="header-search-form" aria-expanded="false" data-search-toggle><?php echo vr_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG ?></a>
				<div class="header-search__form" id="header-search-form"><?php get_search_form(); ?></div>
			</div>
			<button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav">
				<span></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Menu', 'viral-reader' ); ?></span>
			</button>
		</div>
	</div>
</header>

<?php if ( ! is_front_page() && ! is_404() && ! ( is_search() && ! have_posts() ) ) { vr_ad_strip( 'header', 'header-ad' ); } /* no ads on pages without content (404, empty search) */ ?>

<main id="content" class="site-main" tabindex="-1">
