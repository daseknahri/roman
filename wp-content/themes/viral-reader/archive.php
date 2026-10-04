<?php
/**
 * Archive (category, tag, author, date).
 *
 * @package Viral_Reader
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();
?>
<?php $vr_cover = is_category() ? vr_category_cover_url() : ''; ?>
<div class="vr-container">
	<?php if ( '' !== $vr_cover ) : ?>
		<header class="category-hero" style="--cover:url('<?php echo esc_url( $vr_cover ); ?>')">
			<div class="category-hero__inner">
				<?php vr_breadcrumbs(); ?>
				<h1><?php the_archive_title(); ?></h1>
				<?php
				$vr_desc = get_the_archive_description();
				if ( $vr_desc ) { echo '<div class="category-hero__desc">' . wp_kses_post( $vr_desc ) . '</div>'; }
				?>
			</div>
		</header>
	<?php else : ?>
		<header class="archive-header">
			<?php vr_breadcrumbs(); ?>
			<h1><?php the_archive_title(); ?></h1>
			<?php
			$vr_desc = get_the_archive_description();
			if ( $vr_desc ) { echo '<div>' . wp_kses_post( $vr_desc ) . '</div>'; }
			?>
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
			<h2><?php esc_html_e( 'Nothing here yet', 'viral-reader' ); ?></h2>
			<p><?php esc_html_e( 'There are no stories in this section right now. Try a search or head back home.', 'viral-reader' ); ?></p>
			<?php get_search_form(); ?>
			<a class="vr-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back home', 'viral-reader' ); ?></a>
		</div>
	<?php endif; ?>
</div>
<?php
get_footer();
