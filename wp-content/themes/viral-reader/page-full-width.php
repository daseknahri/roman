<?php
/**
 * Template Name: Full Width
 *
 * A page template with NO sidebar — the content uses the full container width.
 * Good for landing pages, wide galleries, or long-form pages that want the room.
 * (Assigned per-page via Page Attributes → Template.)
 *
 * @package Viral_Reader
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();

while ( have_posts() ) :
	the_post();
	?>
	<div class="vr-container">
		<article id="post-<?php the_ID(); ?>" <?php post_class( 'vr-fullwidth' ); ?>>
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
