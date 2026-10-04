<?php
/**
 * Search form.
 *
 * @package Viral_Reader
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<?php $vr_sid = wp_unique_id( 'vr-s-' ); // unique per render: get_search_form() is reused (header + results + widgets) ?>
<form role="search" method="get" class="vr-search" action="<?php echo esc_url( home_url( '/' ) ); ?>">
	<label class="screen-reader-text" for="<?php echo esc_attr( $vr_sid ); ?>"><?php esc_html_e( 'Search for:', 'viral-reader' ); ?></label>
	<input type="search" id="<?php echo esc_attr( $vr_sid ); ?>" name="s" value="<?php echo esc_attr( get_search_query( false ) ); ?>" placeholder="<?php esc_attr_e( 'Search…', 'viral-reader' ); ?>" required>
	<button class="vr-btn" type="submit"><?php esc_html_e( 'Search', 'viral-reader' ); ?></button>
</form>
