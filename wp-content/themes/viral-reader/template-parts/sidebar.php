<?php
/**
 * Sidebar (single/page). Plugin ad zone + widgets + built-in Recent / Categories.
 *
 * @package Viral_Reader
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* Ad zone: plugin "sidebar" first, else the Sidebar widget area. */
$vr_side_ad = function_exists( 'wpap_zone_html' ) ? wpap_zone_html( 'sidebar' ) : '';
if ( '' !== $vr_side_ad ) {
	echo '<div class="sidebar__block">' . $vr_side_ad . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- owner's own ad markup
} elseif ( is_active_sidebar( 'sidebar-1' ) ) {
	dynamic_sidebar( 'sidebar-1' );
}

/* Recent posts. */
$vr_recent = new WP_Query( array(
	'post_type'           => 'post',
	'post_status'         => 'publish',
	'posts_per_page'      => 4,
	'ignore_sticky_posts' => 1,
	'no_found_rows'       => true,
	'post__not_in'        => is_singular( 'post' ) ? array( get_the_ID() ) : array(),
) );
if ( $vr_recent->have_posts() ) : ?>
	<div class="sidebar__block">
		<h2 class="sidebar__title"><?php esc_html_e( 'Recent stories', 'viral-reader' ); ?></h2>
		<ul class="sidebar__list">
			<?php while ( $vr_recent->have_posts() ) : $vr_recent->the_post(); ?>
				<li>
					<a class="sidebar__recent" href="<?php the_permalink(); ?>">
						<?php if ( vr_has_post_image() ) { vr_the_post_image( 'thumbnail', array( 'loading' => 'lazy', 'alt' => '' ) ); } ?>
						<span class="t"><?php echo esc_html( get_the_title() ); ?></span>
					</a>
				</li>
			<?php endwhile; ?>
		</ul>
	</div>
	<?php
endif;
wp_reset_postdata();

/* Categories. */
$vr_cats = vr_top_categories( 6 );
if ( ! empty( $vr_cats ) ) : ?>
	<div class="sidebar__block">
		<h2 class="sidebar__title"><?php esc_html_e( 'Topics', 'viral-reader' ); ?></h2>
		<ul class="sidebar__list">
			<?php foreach ( $vr_cats as $vr_c ) : ?>
				<?php $vr_cl = get_category_link( $vr_c->term_id ); ?>
				<li><a href="<?php echo esc_url( is_wp_error( $vr_cl ) ? '#' : $vr_cl ); ?>"><?php echo esc_html( $vr_c->name ); ?> <span style="color:var(--muted);font-weight:400">(<?php echo esc_html( number_format_i18n( (int) $vr_c->count ) ); ?>)</span></a></li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endif; ?>
