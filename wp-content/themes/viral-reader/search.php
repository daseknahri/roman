<?php
/**
 * Search results.
 *
 * @package Viral_Reader
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();
?>
<div class="vr-container">
	<header class="archive-header">
		<?php vr_breadcrumbs(); ?>
		<h1>
			<?php
			/* translators: %s: search query */
			printf( esc_html__( 'Search results for: %s', 'viral-reader' ), '<span>' . esc_html( get_search_query( false ) ) . '</span>' );
			?>
		</h1>
		<?php if ( have_posts() ) : ?>
			<?php
			$vr_found = (int) $wp_query->found_posts;
			/* translators: %s: number of results */
			echo '<p class="archive-header-count">' . esc_html( sprintf( _n( '%s result', '%s results', $vr_found, 'viral-reader' ), number_format_i18n( $vr_found ) ) ) . '</p>';
			?>
		<?php endif; ?>
		<?php get_search_form(); ?>
	</header>

	<?php if ( have_posts() ) : ?>
		<div class="post-grid section--tight">
			<?php while ( have_posts() ) : the_post(); get_template_part( 'template-parts/card', null, array( 'heading' => 'h2' ) ); endwhile; ?>
		</div>
		<?php vr_pagination(); ?>
	<?php else : ?>
		<div class="vr-empty">
			<h2><?php esc_html_e( 'No results found', 'viral-reader' ); ?></h2>
			<p><?php esc_html_e( 'We couldn’t find anything for that search. Try different keywords.', 'viral-reader' ); ?></p>
			<?php get_search_form(); ?>
			<a class="vr-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back home', 'viral-reader' ); ?></a>
		</div>
	<?php endif; ?>
</div>
<?php
get_footer();
