<?php
/**
 * SEO bez wtyczki SEO: opis do wyników wyszukiwania i znaczniki Open Graph / Twitter na stronach wpisów
 * i stron. Dane ustawia agent (seo_description, social_image) albo właściciel strony w polach własnych.
 *
 * Jeśli kiedyś pojawi się wtyczka SEO, ten moduł wyłącza się filtrem colorbox_mcp_seo_output → false.
 */

namespace Colorbox\WpMcp\Seo;

use Colorbox\WpMcp\Content;

defined( 'ABSPATH' ) || exit;

function boot(): void {
	add_action( 'wp_head', __NAMESPACE__ . '\\output', 5 );
}

function description_for( \WP_Post $post ): string {
	$desc = (string) get_post_meta( $post->ID, Content\META_SEO_DESCRIPTION, true );
	if ( '' === $desc ) {
		$desc = '' !== $post->post_excerpt ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 30, '…' );
	}
	return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $desc ) ) );
}

function image_for( \WP_Post $post ): array {
	$id = (int) get_post_meta( $post->ID, Content\META_SOCIAL_IMAGE, true );
	if ( ! $id ) {
		$id = (int) get_post_thumbnail_id( $post );
	}
	if ( ! $id ) {
		return array();
	}
	$src = wp_get_attachment_image_src( $id, 'large' );
	if ( ! $src ) {
		return array();
	}
	return array(
		'url'    => $src[0],
		'width'  => (int) $src[1],
		'height' => (int) $src[2],
		'alt'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
	);
}

function output(): void {
	if ( ! apply_filters( 'colorbox_mcp_seo_output', true ) || ! is_singular( Content\POST_TYPES ) ) {
		return;
	}
	$post = get_queried_object();
	if ( ! $post instanceof \WP_Post ) {
		return;
	}

	$desc  = description_for( $post );
	$image = image_for( $post );
	$lang  = Content\post_language( $post->ID );
	$tags  = array();

	if ( '' !== $desc ) {
		$tags[] = array( 'name', 'description', $desc );
		$tags[] = array( 'property', 'og:description', $desc );
	}
	$tags[] = array( 'property', 'og:title', wp_strip_all_tags( get_the_title( $post ) ) );
	$tags[] = array( 'property', 'og:type', 'post' === $post->post_type ? 'article' : 'website' );
	$tags[] = array( 'property', 'og:url', (string) get_permalink( $post ) );
	$tags[] = array( 'property', 'og:site_name', get_bloginfo( 'name' ) );

	if ( function_exists( 'pll_languages_list' ) && '' !== $lang ) {
		foreach ( (array) pll_languages_list( array( 'fields' => '' ) ) as $l ) {
			if ( $l->slug === $lang ) {
				$tags[] = array( 'property', 'og:locale', $l->locale );
			}
		}
		foreach ( Content\post_translations( $post->ID ) as $tr_lang => $tr_id ) {
			if ( $tr_lang !== $lang && 'publish' === get_post_status( $tr_id ) ) {
				foreach ( (array) pll_languages_list( array( 'fields' => '' ) ) as $l ) {
					if ( $l->slug === $tr_lang ) {
						$tags[] = array( 'property', 'og:locale:alternate', $l->locale );
					}
				}
			}
		}
	}

	if ( $image ) {
		$tags[] = array( 'property', 'og:image', $image['url'] );
		$tags[] = array( 'property', 'og:image:width', (string) $image['width'] );
		$tags[] = array( 'property', 'og:image:height', (string) $image['height'] );
		if ( '' !== $image['alt'] ) {
			$tags[] = array( 'property', 'og:image:alt', $image['alt'] );
		}
		$tags[] = array( 'name', 'twitter:card', 'summary_large_image' );
	} else {
		$tags[] = array( 'name', 'twitter:card', 'summary' );
	}

	if ( 'post' === $post->post_type ) {
		$tags[] = array( 'property', 'article:published_time', get_post_time( 'c', true, $post ) );
		$tags[] = array( 'property', 'article:modified_time', get_post_modified_time( 'c', true, $post ) );
	}

	echo "\n<!-- colorbox-wp-mcp: SEO -->\n";
	foreach ( $tags as $t ) {
		printf( '<meta %s="%s" content="%s" />' . "\n", esc_attr( $t[0] ), esc_attr( $t[1] ), esc_attr( $t[2] ) );
	}
}
