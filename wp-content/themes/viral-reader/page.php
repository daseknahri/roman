<?php
/**
 * Static page.
 *
 * @package Viral_Reader
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();

while ( have_posts() ) :
	the_post();
	?>
	<div class="vr-container">
		<article id="post-<?php the_ID(); ?>" <?php post_class( 'content-layout--single-post' ); ?>>
			<div class="entry">
				<header class="entry-header">
					<?php vr_breadcrumbs(); ?>
					<h1 class="entry-title"><?php the_title(); ?></h1>
				</header>
				<?php if ( has_post_thumbnail() ) : ?>
					<figure class="entry-featured-media"><?php the_post_thumbnail( 'large' ); ?></figure>
				<?php endif; ?>
				<div class="entry-content">
					<?php
					the_content();
					wp_link_pages( array( 'before' => '<div class="vr-page-links">' . esc_html__( 'Pages:', 'viral-reader' ), 'after' => '</div>' ) );
					?>
				</div>
			</div>
			<aside class="sidebar" aria-label="<?php esc_attr_e( 'Sidebar', 'viral-reader' ); ?>">
				<?php get_template_part( 'template-parts/sidebar' ); ?>
			</aside>
		</article>
	</div>
	<?php
	if ( comments_open() || get_comments_number() ) {
		echo '<div class="vr-container">';
		comments_template();
		echo '</div>';
	}
endwhile;

get_footer();
