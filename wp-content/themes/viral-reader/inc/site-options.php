<?php
/**
 * Site options — Customizer → "Site options".
 *
 * Per-site presentation choices an owner sets in wp-admin, no code needed. Every default reproduces the
 * theme's previous behaviour, so a site that never opens this panel renders exactly as before.
 *
 * @package Viral_Reader
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* Read one site option (theme_mod) with its default. */
function vr_opt( $key ) {
	$defaults = array(
		'noun'          => 'story',
		'topic_covers'  => false,
		'byline_author' => true,
		'hero_style'    => 'overlay',
	);
	$default = array_key_exists( $key, $defaults ) ? $defaults[ $key ] : null;
	return get_theme_mod( 'vr_' . $key, $default );
}

function vr_sanitize_noun( $value ) {
	return in_array( $value, array( 'story', 'recipe', 'article' ), true ) ? $value : 'story';
}

function vr_sanitize_checkbox( $value ) {
	return (bool) $value;
}

function vr_sanitize_hero_style( $value ) {
	return in_array( $value, array( 'overlay', 'stacked' ), true ) ? $value : 'overlay';
}

/* Hero layout as a body class, so style.css can restyle the homepage hero. */
add_filter( 'body_class', function ( $classes ) {
	if ( 'stacked' === vr_opt( 'hero_style' ) ) {
		$classes[] = 'vr-hero-stacked';
	}
	return $classes;
} );

add_action( 'customize_register', function ( $wp_customize ) {
	$wp_customize->add_section( 'vr_site_options', array(
		'title'       => __( 'Site options', 'viral-reader' ),
		'description' => __( 'Presentation choices for this site. Changes preview live; nothing is saved until you click Publish.', 'viral-reader' ),
		'priority'    => 30,
	) );

	$wp_customize->add_setting( 'vr_noun', array( 'default' => 'story', 'sanitize_callback' => 'vr_sanitize_noun' ) );
	$wp_customize->add_control( 'vr_noun', array(
		'section'     => 'vr_site_options',
		'type'        => 'select',
		'label'       => __( 'What this site publishes', 'viral-reader' ),
		'description' => __( 'Sets the word used in buttons and headings, e.g. "Read the recipe", "Latest recipes", "12 recipes".', 'viral-reader' ),
		'choices'     => array(
			'story'   => __( 'Stories', 'viral-reader' ),
			'recipe'  => __( 'Recipes', 'viral-reader' ),
			'article' => __( 'Articles', 'viral-reader' ),
		),
	) );

	$wp_customize->add_setting( 'vr_hero_style', array( 'default' => 'overlay', 'sanitize_callback' => 'vr_sanitize_hero_style' ) );
	$wp_customize->add_control( 'vr_hero_style', array(
		'section'     => 'vr_site_options',
		'type'        => 'radio',
		'label'       => __( 'Homepage hero layout', 'viral-reader' ),
		'description' => __( 'Choose "Photo above the headline" when post images have text or collages printed on them, so the headline never sits on top of busy pictures.', 'viral-reader' ),
		'choices'     => array(
			'overlay' => __( 'Headline over the photo', 'viral-reader' ),
			'stacked' => __( 'Photo above the headline', 'viral-reader' ),
		),
	) );

	$wp_customize->add_setting( 'vr_topic_covers', array( 'default' => false, 'sanitize_callback' => 'vr_sanitize_checkbox' ) );
	$wp_customize->add_control( 'vr_topic_covers', array(
		'section'     => 'vr_site_options',
		'type'        => 'checkbox',
		'label'       => __( 'Photos on topic tiles', 'viral-reader' ),
		'description' => __( 'When a category has no cover image of its own, its homepage tile uses the photo of its newest post instead of staying blank. (Category page headers stay plain, so their title is never printed over a busy photo.)', 'viral-reader' ),
	) );

	$wp_customize->add_setting( 'vr_byline_author', array( 'default' => true, 'sanitize_callback' => 'vr_sanitize_checkbox' ) );
	$wp_customize->add_control( 'vr_byline_author', array(
		'section'     => 'vr_site_options',
		'type'        => 'checkbox',
		'label'       => __( 'Show the author name and photo on posts', 'viral-reader' ),
		'description' => __( 'Untick for a brand-voice site: posts then show only the date and reading time under the title.', 'viral-reader' ),
	) );

	$wp_customize->add_section( 'vr_brand_social', array(
		'title'       => __( 'Social profiles', 'viral-reader' ),
		'description' => __( "This site's own pages on social networks. Each address you fill in shows as an icon in the footer, so readers can follow you. Leave a box empty to hide that network.", 'viral-reader' ),
		'priority'    => 31,
	) );
	foreach ( vr_brand_social_networks() as $net => $label ) {
		$wp_customize->add_setting( 'vr_brand_social_' . $net, array( 'default' => '', 'sanitize_callback' => 'esc_url_raw' ) );
		$wp_customize->add_control( 'vr_brand_social_' . $net, array(
			'section'     => 'vr_brand_social',
			'type'        => 'url',
			/* translators: %s: social network name (e.g. "Facebook") */
			'label'       => sprintf( __( '%s page address', 'viral-reader' ), $label ),
			'input_attrs' => array( 'placeholder' => 'https://' ),
		) );
	}
} );

/* ── Social profiles: the networks the Customizer offers (each has a footer icon). ── */
function vr_brand_social_networks() {
	return array(
		'facebook'  => 'Facebook',
		'pinterest' => 'Pinterest',
		'instagram' => 'Instagram',
		'x'         => 'X',
		'youtube'   => 'YouTube',
		'tiktok'    => 'TikTok',
		'linkedin'  => 'LinkedIn',
	);
}

/* Customizer profiles feed the footer icon row. Runs early, so a site's own vr_social_profiles filter
   can still add to or replace them. */
add_filter( 'vr_social_profiles', function ( $profiles ) {
	$profiles = is_array( $profiles ) ? $profiles : array();
	foreach ( vr_brand_social_networks() as $net => $label ) {
		$url = trim( (string) get_theme_mod( 'vr_brand_social_' . $net, '' ) );
		if ( '' !== $url ) {
			$profiles[] = array( 'network' => $net, 'url' => $url );
		}
	}
	return $profiles;
}, 5 );

/* ── Noun: swap "story/stories" in this theme's own strings for the chosen word. ── */
function vr_swap_noun( $text ) {
	/* Front end only: wp-admin (incl. this panel's own "Stories" choice) keeps the original wording. */
	if ( is_admin() || false === stripos( $text, 'stor' ) ) {
		return $text;
	}
	$noun = vr_opt( 'noun' );
	if ( 'story' === $noun ) {
		return $text;
	}
	$plural = ( 'recipe' === $noun ) ? 'recipes' : 'articles';
	return preg_replace_callback( '/\b(stories|story)\b/i', static function ( $m ) use ( $noun, $plural ) {
		$word = ( 0 === strcasecmp( $m[1], 'stories' ) ) ? $plural : $noun;
		if ( strtoupper( $m[1] ) === $m[1] ) { return strtoupper( $word ); }
		if ( ucfirst( strtolower( $m[1] ) ) === $m[1] ) { return ucfirst( $word ); }
		return $word;
	}, $text );
}
add_filter( 'gettext_viral-reader', 'vr_swap_noun' );
add_filter( 'ngettext_viral-reader', 'vr_swap_noun' );
add_filter( 'gettext_with_context_viral-reader', 'vr_swap_noun' );

/* ── Topic covers: fall back to the newest post photo in the category. Runs last, so a site's own
   vr_category_cover_url mapping always wins. ── */
add_filter( 'vr_category_cover_url', function ( $url, $term_id ) {
	if ( '' !== $url || ! $term_id || ! vr_opt( 'topic_covers' ) || is_category() ) {
		return $url;
	}
	static $cache = array();
	if ( isset( $cache[ $term_id ] ) ) {
		return $cache[ $term_id ];
	}
	$ids = get_posts( array(
		'cat'              => (int) $term_id,
		'numberposts'      => 1,
		'fields'           => 'ids',
		'meta_key'         => '_thumbnail_id', // phpcs:ignore WordPress.DB.SlowDBQuery -- one row, cached per request
		'no_found_rows'    => true,
		'suppress_filters' => false,
	) );
	$img = $ids ? wp_get_attachment_image_url( (int) get_post_thumbnail_id( $ids[0] ), 'large' ) : '';
	$cache[ $term_id ] = $img ? (string) $img : '';
	return $cache[ $term_id ];
}, 99, 2 );
