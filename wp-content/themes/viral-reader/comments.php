<?php
/**
 * Comments template.
 *
 * @package Viral_Reader
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * If the current post is protected by a password and the visitor has not yet
 * entered the password, bail — we don't want to show comments in that case.
 */
if ( post_password_required() ) {
	return;
}
?>

<section id="comments" class="comments-area">

	<?php if ( have_comments() ) : ?>
		<h2 class="comments-title">
			<?php
			$vr_count = get_comments_number();
			if ( '1' === $vr_count ) {
				esc_html_e( 'One response', 'viral-reader' );
			} else {
				printf(
					/* translators: %s: comment count number. */
					esc_html( _n( '%s response', '%s responses', (int) $vr_count, 'viral-reader' ) ),
					esc_html( number_format_i18n( $vr_count ) )
				);
			}
			?>
		</h2>

		<?php the_comments_navigation(); ?>

		<ol class="comment-list">
			<?php
			wp_list_comments(
				array(
					'style'       => 'ol',
					'short_ping'  => true,
					'avatar_size' => 44,
				)
			);
			?>
		</ol>

		<?php
		the_comments_navigation();

		if ( ! comments_open() ) :
			?>
			<p class="no-comments"><?php esc_html_e( 'Comments are closed.', 'viral-reader' ); ?></p>
			<?php
		endif;
		?>

	<?php endif; // have_comments(). ?>

	<?php
	comment_form(
		array(
			'class_submit' => 'vr-btn',
			'title_reply'  => esc_html__( 'Leave a comment', 'viral-reader' ),
		)
	);
	?>

</section>
