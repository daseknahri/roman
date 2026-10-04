<?php
/**
 * Footer.
 *
 * @package Viral_Reader
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
</main><!-- #content -->

<?php
/* Footer ad strip (plugin "footer" zone; no widget fallback here — the footer
   widget columns below are separate). */
if ( function_exists( 'wpap_zone_html' ) ) {
	$vr_foot_ad = wpap_zone_html( 'footer' );
	if ( '' !== $vr_foot_ad ) {
		echo '<div class="vr-ad-strip"><div class="vr-ad-strip__inner">' . $vr_foot_ad . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- owner's own ad markup
	}
}
?>

<footer class="site-footer">
	<div class="site-footer__grid">
		<div class="site-footer__brand">
			<a class="n" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a>
			<?php $vr_desc = get_bloginfo( 'description', 'display' ); ?>
			<?php if ( $vr_desc ) : ?><p><?php echo esc_html( $vr_desc ); ?></p><?php endif; ?>
			<?php
			if ( function_exists( 'vr_brand_social_html' ) ) {
				echo vr_brand_social_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_url/esc_attr + static inline SVG
			}
			?>
		</div>

		<div class="site-footer__explore">
			<h2><?php esc_html_e( 'Explore', 'viral-reader' ); ?></h2>
			<ul>
				<?php
				foreach ( vr_top_categories( 5 ) as $cat ) {
					$link = get_category_link( $cat->term_id );
					if ( is_wp_error( $link ) ) { continue; }
					echo '<li><a href="' . esc_url( $link ) . '">' . esc_html( $cat->name ) . '</a></li>';
				}
				?>
			</ul>
		</div>

		<div class="site-footer__info">
			<h2><?php esc_html_e( 'Information', 'viral-reader' ); ?></h2>
			<?php
			if ( has_nav_menu( 'footer' ) ) {
				wp_nav_menu( array( 'theme_location' => 'footer', 'container' => false, 'depth' => 1, 'items_wrap' => '<ul>%3$s</ul>' ) );
			} else {
				echo '<ul>';
				foreach ( vr_footer_info_links() as $vr_il ) {
					if ( empty( $vr_il['url'] ) || '' === (string) $vr_il['label'] ) { continue; }
					echo '<li><a href="' . esc_url( $vr_il['url'] ) . '">' . esc_html( $vr_il['label'] ) . '</a></li>';
				}
				echo '</ul>';
			}
			?>
		</div>
	</div>

	<div class="site-footer__bottom">
		&copy; <?php echo esc_html( date_i18n( 'Y' ) ); ?> <?php echo esc_html( get_bloginfo( 'name' ) ); ?>. <?php esc_html_e( 'All rights reserved.', 'viral-reader' ); ?>
		<?php if ( function_exists( 'get_the_privacy_policy_link' ) && get_the_privacy_policy_link() ) : ?>
			<span class="sep">&middot;</span><?php echo wp_kses_post( get_the_privacy_policy_link() ); ?>
		<?php endif; ?>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
