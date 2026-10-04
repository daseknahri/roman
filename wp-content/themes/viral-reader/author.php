<?php
/**
 * Author archive — author identity header + their stories.
 *
 * Without this, author archives fell through to archive.php, which shows only a
 * bare "Author: Name" title (core returns no description for author archives),
 * giving the author's own page less context than a single post's author box.
 *
 * @package Viral_Reader
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();

/* The author object is available from the first post; fall back to the queried
   author id when the author has no posts. */
$vr_author_id = (int) get_queried_object_id();
$vr_bio       = get_the_author_meta( 'description', $vr_author_id );
$vr_name      = get_the_author_meta( 'display_name', $vr_author_id );
/* Reuse the main author query's total (already computed for pagination) instead of
   a separate uncached COUNT; fall back to count_user_posts if it's unavailable. */
$vr_count     = isset( $GLOBALS['wp_query']->found_posts )
	? (int) $GLOBALS['wp_query']->found_posts
	: (int) count_user_posts( $vr_author_id, 'post', true );
?>
<div class="vr-container">
	<header class="archive-header">
		<?php vr_breadcrumbs(); ?>
	</header>

	<div class="author-header">
		<?php echo get_avatar( $vr_author_id, 72, '', '', array( 'class' => 'author-header__avatar' ) ); ?>
		<div class="author-header__body">
			<p class="author-header__eyebrow"><?php esc_html_e( 'Author', 'viral-reader' ); ?></p>
			<h1 class="author-header__name"><?php echo esc_html( $vr_name ); ?></h1>
			<?php if ( $vr_bio ) : ?><p class="author-header__bio"><?php echo esc_html( $vr_bio ); ?></p><?php endif; ?>
			<?php
			/* Link to the fuller "About the Author" page when a site provides one (filter),
			   so the noindexed author archive and the indexed bio page are connected. */
			$vr_bio_url = (string) apply_filters( 'vr_author_profile_url', '', $vr_author_id );
			if ( '' !== $vr_bio_url ) :
				?>
				<p class="author-header__more"><a href="<?php echo esc_url( $vr_bio_url ); ?>"><?php esc_html_e( 'Read the full bio', 'viral-reader' ); ?> <span aria-hidden="true">&rarr;</span></a></p>
			<?php endif; ?>
			<?php echo vr_author_social_html( $vr_author_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper ?>
			<p class="author-header__count"><?php /* translators: %s: number of stories */ echo esc_html( sprintf( _n( '%s story', '%s stories', $vr_count, 'viral-reader' ), number_format_i18n( $vr_count ) ) ); ?></p>
		</div>
	</div>

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
			<h2><?php esc_html_e( 'No stories yet', 'viral-reader' ); ?></h2>
			<p><?php esc_html_e( 'This author hasn’t published anything yet.', 'viral-reader' ); ?></p>
			<a class="vr-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back home', 'viral-reader' ); ?></a>
		</div>
	<?php endif; ?>
</div>
<?php
get_footer();
