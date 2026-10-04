<?php
/**
 * 404.
 *
 * @package Viral_Reader
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();
?>
<div class="vr-container section">
	<p class="eyebrow">404</p>
	<h1><?php esc_html_e( 'Page not found', 'viral-reader' ); ?></h1>
	<p style="color:var(--muted);max-width:52ch"><?php esc_html_e( 'The page you were looking for isn\'t here. Try a search, or head back to the homepage.', 'viral-reader' ); ?></p>
	<?php get_search_form(); ?>
	<?php
	/* Help a lost visitor self-navigate: link the site's main sections. */
	$vr_404_cats = vr_top_categories( 6 );
	if ( ! empty( $vr_404_cats ) ) :
		?>
		<nav class="vr-404-topics" aria-label="<?php esc_attr_e( 'Browse topics', 'viral-reader' ); ?>">
			<p class="eyebrow"><?php esc_html_e( 'Or browse a topic', 'viral-reader' ); ?></p>
			<ul>
				<?php foreach ( $vr_404_cats as $vr_404_c ) : $vr_404_l = get_category_link( $vr_404_c->term_id ); ?>
					<li><a href="<?php echo esc_url( is_wp_error( $vr_404_l ) ? '#' : $vr_404_l ); ?>"><?php echo esc_html( $vr_404_c->name ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</nav>
	<?php endif; ?>
	<p style="margin-top:1.2em"><a class="vr-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to homepage', 'viral-reader' ); ?></a></p>
</div>
<?php
get_footer();
