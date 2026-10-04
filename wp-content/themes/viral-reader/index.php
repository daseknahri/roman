<?php
/**
 * Fallback index / blog home.
 *
 * @package Viral_Reader
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();
?>
<div class="vr-container">
	<?php if ( is_home() && ! is_front_page() ) :
		/* Surface the posts-index page's own intro copy (page_for_posts pages don't render
		   their content by default — WP shows the loop) as a header description on page 1. */
		$vr_posts_page = (int) get_option( 'page_for_posts' );
		$vr_pp_post    = $vr_posts_page ? get_post( $vr_posts_page ) : null;
		?>
		<header class="archive-header">
			<?php vr_breadcrumbs(); ?>
			<h1><?php single_post_title(); ?></h1>
			<?php if ( $vr_pp_post && ! is_paged() && '' !== trim( (string) $vr_pp_post->post_content ) ) : ?>
				<div class="archive-header__intro"><?php echo wp_kses_post( wpautop( $vr_pp_post->post_content ) ); ?></div>
			<?php endif; ?>
		</header>
	<?php endif; ?>

	<?php if ( have_posts() ) : ?>
		<div class="post-grid section--tight">
			<?php
			$vr_i = 0;
			while ( have_posts() ) :
				the_post();
				get_template_part( 'template-parts/card', null, array( 'lead' => ( 0 === $vr_i && ! is_paged() ), 'heading' => 'h2' ) );
				$vr_i++;
			endwhile;
			?>
		</div>
		<?php vr_pagination(); ?>
	<?php else : ?>
		<div class="vr-empty">
			<h2><?php esc_html_e( 'No posts yet', 'viral-reader' ); ?></h2>
			<p><?php esc_html_e( 'There’s nothing here yet — check back soon.', 'viral-reader' ); ?></p>
			<a class="vr-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back home', 'viral-reader' ); ?></a>
		</div>
	<?php endif; ?>
</div>
<?php
get_footer();
