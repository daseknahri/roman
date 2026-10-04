<?php
/**
 * Post card for lists (home, archive, search).
 *
 * @package Viral_Reader
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$vr_lead = ! empty( $args['lead'] );
$vr_alt  = wp_strip_all_tags( get_the_title() );
/* Heading level is caller-controlled so the card sits correctly in the page's
   outline: h2 on listing views (archive/search/index) where it follows the page
   h1 directly, h3 on the front page where it sits under a "Latest stories" h2. */
$vr_htag = ( isset( $args['heading'] ) && in_array( $args['heading'], array( 'h2', 'h3' ), true ) ) ? $args['heading'] : 'h3';
?>
<article <?php post_class( $vr_lead ? 'post-card is-lead' : 'post-card' ); ?>>
	<?php if ( vr_has_post_image() ) : ?>
		<a class="post-card__media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
			<?php
			/* The lead (full-width) card is the LCP element on archives/index/search,
			   where the singular-only vr_lcp_priority() hint never runs — so load it
			   eagerly with high fetchpriority instead of lazy. Non-lead cards stay lazy.
			   vr_the_post_image() renders the local thumbnail or the external fallback. */
			$vr_img_attr = $vr_lead
				? array( 'loading' => 'eager', 'fetchpriority' => 'high', 'alt' => $vr_alt )
				: array( 'loading' => 'lazy', 'alt' => $vr_alt );
			vr_the_post_image( $vr_lead ? 'large' : 'medium_large', $vr_img_attr );
			?>
		</a>
	<?php endif; ?>
	<div class="post-card__body">
		<div class="content-chip-row">
			<?php
			$vr_chip = vr_primary_category_chip();
			if ( $vr_chip ) {
				echo $vr_chip; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper
			}
			?>
		</div>
		<?php printf( '<%1$s class="post-card__title"><a href="%2$s">%3$s</a></%1$s>', tag_escape( $vr_htag ), esc_url( get_permalink() ), esc_html( get_the_title() ) ); ?>
		<p class="post-card__excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), $vr_lead ? 32 : 22, '…' ) ); ?></p>
		<?php vr_card_meta(); ?>
	</div>
</article>
