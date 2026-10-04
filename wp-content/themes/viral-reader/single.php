<?php
/**
 * Single post.
 *
 * The article body is rendered via the_content(), which is where the WP
 * Automator Pro plugin injects its in-content ads — the theme does not inject
 * its own, so ads never double up.
 *
 * Layout: the article and the sidebar (which carries the plugin's sidebar ad
 * zone) sit in a two-column grid; the author box, related posts, post nav and
 * comments render full-width BELOW that grid. On mobile the grid collapses to
 * one column, so the sidebar — and its ad — appears right after the article,
 * a high-viewability spot, instead of being buried under the comments.
 *
 * @package Viral_Reader
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();

while ( have_posts() ) :
	the_post();
	$vr_cat_chip = vr_primary_category_chip();
	$vr_shares   = vr_share_links();
	$vr_share_ic = array( 'facebook' => 'facebook', 'whatsapp' => 'whatsapp', 'email' => 'email', 'copy' => 'link', 'print' => 'print' );
	?>
	<div class="vr-container">
		<article id="post-<?php the_ID(); ?>" <?php post_class( 'content-layout--single-post' ); ?>>
			<div class="entry" data-reading-progress-source>
				<header class="entry-header">
					<?php vr_breadcrumbs(); ?>
					<?php if ( $vr_cat_chip ) : ?>
						<div class="entry-toolbar"><?php echo $vr_cat_chip; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper ?></div>
					<?php endif; ?>
					<h1 class="entry-title"><?php the_title(); ?></h1>
					<?php if ( has_excerpt() ) : ?>
						<p class="entry-excerpt"><?php echo esc_html( get_the_excerpt() ); ?></p>
					<?php endif; ?>

					<?php $vr_author_id = (int) get_the_author_meta( 'ID' ); ?>
					<?php $vr_show_author = (bool) vr_opt( 'byline_author' ); ?><div class="entry-byline<?php echo $vr_show_author ? '' : ' entry-byline--no-author'; ?>">
						<?php if ( $vr_show_author ) { echo get_avatar( $vr_author_id, 40, '', '', array( 'class' => 'entry-byline__avatar' ) ); } ?>
						<div class="entry-byline__text">
							<?php if ( $vr_show_author ) : ?><span class="entry-byline__name"><a href="<?php echo esc_url( get_author_posts_url( $vr_author_id ) ); ?>" rel="author"><?php echo esc_html( get_the_author() ); ?></a></span><?php endif; ?>
							<span class="entry-byline__meta">
								<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
								&middot; <?php echo esc_html( vr_reading_time() ); ?>
								<?php if ( get_the_modified_time( 'U' ) > get_the_time( 'U' ) + DAY_IN_SECONDS ) : ?>
									&middot; <span class="entry-byline__updated"><time datetime="<?php echo esc_attr( get_the_modified_date( 'c' ) ); ?>"><?php /* translators: %s: date */ printf( esc_html__( 'Updated %s', 'viral-reader' ), esc_html( get_the_modified_date() ) ); ?></time></span>
								<?php endif; ?>
							</span>
						</div>
					</div>

					<?php if ( vr_has_post_image() ) : ?>
						<?php
						$vr_thumb_id  = get_post_thumbnail_id();
						$vr_thumb_alt = trim( (string) get_post_meta( $vr_thumb_id, '_wp_attachment_image_alt', true ) );
						if ( '' === $vr_thumb_alt ) { $vr_thumb_alt = wp_strip_all_tags( get_the_title() ); }
						?>
						<figure class="entry-featured-media">
							<?php
							/* The single-post LCP element — eager + high priority to match the
							   preload in vr_preload_lcp(). Renders the local thumbnail or the
							   external (_wpap_image_url) fallback, so the preload is never wasted. */
							vr_the_post_image( 'large', array(
								'alt'           => $vr_thumb_alt,
								'sizes'         => '(max-width:720px) 100vw, 680px',
								'loading'       => 'eager',
								'fetchpriority' => 'high',
							) );
							?>
							<?php $vr_cap = $vr_thumb_id ? wp_get_attachment_caption( $vr_thumb_id ) : ''; ?>
							<?php if ( $vr_cap ) : ?><figcaption><?php echo esc_html( $vr_cap ); ?></figcaption><?php endif; ?>
						</figure>
					<?php endif; ?>

					<div class="share-tools" role="group" aria-label="<?php esc_attr_e( 'Share this story', 'viral-reader' ); ?>">
						<?php foreach ( $vr_shares as $vr_s ) : ?>
							<?php $vr_ic = isset( $vr_share_ic[ $vr_s['type'] ] ) ? $vr_share_ic[ $vr_s['type'] ] : 'link'; ?>
							<?php if ( 'copy' === $vr_s['type'] ) : ?>
								<button class="share-tools__button" type="button" data-copy-url="<?php echo esc_attr( $vr_s['url'] ); ?>" aria-label="<?php echo esc_attr( $vr_s['label'] ); ?>" title="<?php echo esc_attr( $vr_s['label'] ); ?>"><?php echo vr_icon( $vr_ic ); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG ?></button>
							<?php elseif ( 'print' === $vr_s['type'] ) : ?>
								<button class="share-tools__button" type="button" data-print aria-label="<?php echo esc_attr( $vr_s['label'] ); ?>" title="<?php echo esc_attr( $vr_s['label'] ); ?>"><?php echo vr_icon( $vr_ic ); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG ?></button>
							<?php else : ?>
								<a class="share-tools__button" href="<?php echo esc_url( $vr_s['url'] ); ?>" target="_blank" rel="noopener nofollow" aria-label="<?php echo esc_attr( $vr_s['label'] ); ?>" title="<?php echo esc_attr( $vr_s['label'] ); ?>"><?php echo vr_icon( $vr_ic ); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG ?></a>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				</header>

				<div class="entry-content">
					<?php
					the_content();
					wp_link_pages( array( 'before' => '<div class="vr-page-links">' . esc_html__( 'Pages:', 'viral-reader' ), 'after' => '</div>' ) );
					?>
				</div>

				<?php $vr_tags = get_the_tag_list( '', '' ); ?>
				<?php if ( $vr_tags ) : ?>
					<div class="entry-tags"><?php echo wp_kses_post( $vr_tags ); ?></div>
				<?php endif; ?>
			</div>

			<aside class="sidebar" aria-label="<?php esc_attr_e( 'Article context', 'viral-reader' ); ?>">
				<?php get_template_part( 'template-parts/sidebar' ); ?>
			</aside>
		</article>

		<?php /* Full-width below the grid — on mobile these follow the sidebar ad. */ ?>
		<div class="single-extras">
			<?php
			$vr_bio = get_the_author_meta( 'description' );
			if ( $vr_bio && vr_opt( 'byline_author' ) ) :
				$vr_author_id = (int) get_the_author_meta( 'ID' );
				?>
				<aside class="author-box">
					<?php echo get_avatar( $vr_author_id, 72, '', '', array( 'class' => 'author-box__avatar' ) ); ?>
					<div class="author-box__body">
						<p class="author-box__eyebrow"><?php esc_html_e( 'Written by', 'viral-reader' ); ?></p>
						<p class="author-box__name"><?php echo esc_html( get_the_author() ); ?></p>
						<p class="author-box__bio"><?php echo esc_html( $vr_bio ); ?></p>
						<?php echo vr_author_social_html( $vr_author_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper ?>
						<a class="author-box__link" href="<?php echo esc_url( get_author_posts_url( $vr_author_id ) ); ?>"><?php esc_html_e( 'More stories by this author →', 'viral-reader' ); ?></a>
					</div>
				</aside>
			<?php endif; ?>

			<?php
			$vr_related = vr_related_posts( get_the_ID(), 4 );
			if ( $vr_related ) :
				?>
				<section class="related-posts">
					<h2 class="eyebrow"><?php esc_html_e( 'Keep reading', 'viral-reader' ); ?></h2>
					<div class="related-grid">
						<?php foreach ( $vr_related as $vr_r ) : ?>
							<article class="related-card">
								<?php if ( vr_has_post_image( $vr_r->ID ) ) : ?>
									<a class="related-card__media" href="<?php echo esc_url( get_permalink( $vr_r ) ); ?>" tabindex="-1" aria-hidden="true"><?php vr_the_post_image( 'medium', array( 'loading' => 'lazy', 'alt' => '' ), $vr_r->ID ); ?></a>
								<?php endif; ?>
								<div class="related-card__body">
									<h3><a href="<?php echo esc_url( get_permalink( $vr_r ) ); ?>"><?php echo esc_html( get_the_title( $vr_r ) ); ?></a></h3>
								</div>
							</article>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>

			<?php
			/* Same-category prev/next keeps the reader on-topic (topic retention for the
			   ~99% Facebook→article audience). Falls back gracefully: the guard below omits
			   the block when there's no same-category neighbour. Opt out with
			   add_filter( 'vr_same_category_post_nav', '__return_false' ) for chronological. */
			$vr_same_cat = (bool) apply_filters( 'vr_same_category_post_nav', true );
			$vr_prev = get_previous_post( $vr_same_cat );
			$vr_next = get_next_post( $vr_same_cat );
			if ( $vr_prev || $vr_next ) :
				?>
				<nav class="post-navigation-simple" aria-label="<?php esc_attr_e( 'Post navigation', 'viral-reader' ); ?>">
					<?php if ( $vr_prev ) : ?>
						<a class="post-navigation-simple__item post-navigation-simple__item--prev" href="<?php echo esc_url( get_permalink( $vr_prev ) ); ?>">
							<span class="post-navigation-simple__eyebrow"><?php esc_html_e( 'Previous', 'viral-reader' ); ?></span>
							<strong><?php echo esc_html( get_the_title( $vr_prev ) ); ?></strong>
						</a>
					<?php else : ?><span></span><?php endif; ?>
					<?php if ( $vr_next ) : ?>
						<a class="post-navigation-simple__item post-navigation-simple__item--next" href="<?php echo esc_url( get_permalink( $vr_next ) ); ?>">
							<span class="post-navigation-simple__eyebrow"><?php esc_html_e( 'Next', 'viral-reader' ); ?></span>
							<strong><?php echo esc_html( get_the_title( $vr_next ) ); ?></strong>
						</a>
					<?php endif; ?>
				</nav>
			<?php endif; ?>

			<?php
			if ( comments_open() || get_comments_number() ) {
				comments_template();
			}
			?>
		</div>
	</div>
	<?php
endwhile;

get_footer();
