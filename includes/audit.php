<?php
/**
 * Raport przeglądu treści (tylko odczyt): brakujące tłumaczenia, obrazki wyróżniające, opisy SEO,
 * obrazki bez alt, stare szkice i — na żądanie — zepsute linki.
 */

namespace Colorbox\WpMcp\Audit;

use Colorbox\WpMcp\Content;

defined( 'ABSPATH' ) || exit;

const STALE_DAYS = 30;

/**
 * @param array<string, mixed> $in type (post|page|any), check_links (bool), max_links (int)
 * @return array<string, mixed>
 */
function content_audit( array $in ): array {
	$type = (string) ( $in['type'] ?? 'any' );
	$type = in_array( $type, Content\POST_TYPES, true ) ? $type : Content\POST_TYPES;

	$posts = get_posts(
		array(
			'post_type'   => $type,
			'post_status' => Content\LISTABLE_STATUSES,
			'numberposts' => 500,
			'lang'        => '',
		)
	);

	$langs  = Content\language_slugs();
	$issues = array(
		'no_language'         => array(),
		'missing_translation' => array(),
		'no_featured_image'   => array(),
		'no_seo_description'  => array(),
		'image_without_alt'   => array(),
		'stale_draft'         => array(),
		'broken_link'         => array(),
	);
	$links  = array();

	foreach ( $posts as $post ) {
		$ref = array(
			'id'     => $post->ID,
			'type'   => $post->post_type,
			'title'  => get_the_title( $post ),
			'status' => $post->post_status,
			'lang'   => Content\post_language( $post->ID ),
		);

		if ( '' === $ref['lang'] ) {
			$issues['no_language'][] = $ref;
		}

		$missing = array_values( array_diff( $langs, array_keys( Content\post_translations( $post->ID ) ) ) );
		// Nie zgłaszamy dwa razy tej samej pary: tylko od strony języka domyślnego albo gdy język nieznany.
		if ( $missing && '' !== $ref['lang'] && ( $ref['lang'] === Content\default_language() || ! in_array( Content\default_language(), array_keys( Content\post_translations( $post->ID ) ), true ) ) ) {
			$issues['missing_translation'][] = $ref + array( 'missing' => $missing );
		}

		if ( 'post' === $post->post_type && ! get_post_thumbnail_id( $post ) ) {
			$issues['no_featured_image'][] = $ref;
		}

		if ( '' === (string) get_post_meta( $post->ID, Content\META_SEO_DESCRIPTION, true ) && '' === trim( $post->post_excerpt ) ) {
			$issues['no_seo_description'][] = $ref;
		}

		foreach ( images_without_alt( $post->post_content ) as $src ) {
			$issues['image_without_alt'][] = $ref + array( 'image' => $src );
		}

		// Szkice mają post_modified_gmt = 0000-00-00, więc liczymy z czasu lokalnego.
		if ( 'draft' === $post->post_status && strtotime( get_gmt_from_date( $post->post_modified ) . ' UTC' ) < time() - STALE_DAYS * DAY_IN_SECONDS ) {
			$issues['stale_draft'][] = $ref + array( 'modified' => $post->post_modified );
		}

		if ( ! empty( $in['check_links'] ) && preg_match_all( '#<a\s[^>]*href=["\']([^"\']+)["\']#i', $post->post_content, $m ) ) {
			foreach ( $m[1] as $href ) {
				$href = html_entity_decode( $href );
				if ( preg_match( '#^https?://#i', $href ) ) {
					$links[ $href ][] = $ref;
				}
			}
		}
	}

	$checked = 0;
	if ( ! empty( $in['check_links'] ) ) {
		$max = max( 1, min( 100, (int) ( $in['max_links'] ?? 30 ) ) );
		foreach ( $links as $href => $refs ) {
			if ( $checked >= $max ) {
				break;
			}
			++$checked;
			$problem = check_link( $href );
			if ( '' !== $problem ) {
				foreach ( $refs as $ref ) {
					$issues['broken_link'][] = $ref + array(
						'url'     => $href,
						'problem' => $problem,
					);
				}
			}
		}
	}

	return array(
		'checked_items' => count( $posts ),
		'links_found'   => count( $links ),
		'links_checked' => $checked,
		'summary'       => array_map( 'count', $issues ),
		'issues'        => $issues,
	);
}

/**
 * Źródła obrazków bez atrybutu alt albo z pustym alt (w treści bloków).
 *
 * @return string[]
 */
function images_without_alt( string $html ): array {
	$out = array();
	if ( ! preg_match_all( '#<img\s[^>]*>#i', $html, $m ) ) {
		return $out;
	}
	foreach ( $m[0] as $tag ) {
		if ( ! preg_match( '#\salt=["\']([^"\']*)["\']#i', $tag, $alt ) || '' === trim( $alt[1] ) ) {
			$out[] = preg_match( '#\ssrc=["\']([^"\']+)["\']#i', $tag, $src ) ? $src[1] : __( '(no src)', 'colorbox-wp-mcp' );
		}
	}
	return $out;
}

/**
 * Pusty wynik = link działa. Linki wewnętrzne sprawdzamy w bazie (działa też, gdy strona
 * jest za planszą „w budowie” albo w trybie konserwacji). Zewnętrzne: HEAD, a gdy serwer go nie obsługuje — GET.
 */
function check_link( string $url ): string {
	$home = home_url( '/' );
	if ( 0 === strpos( $url, $home ) || 0 === strpos( $url, set_url_scheme( $home, 'http' ) ) ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '/' === $path || '' === $path || preg_match( '#^/(wp-content|wp-admin|wp-includes|wp-json)/|^/wp-login\.php#', $path ) ) {
			return '';
		}
		$id = url_to_postid( $url );
		if ( ! $id ) {
			/* translators: %s: site host name */
			return sprintf( __( 'no such page on %s', 'colorbox-wp-mcp' ), wp_parse_url( home_url(), PHP_URL_HOST ) );
		}
		$status = get_post_status( $id );
		/* translators: %s: post status slug */
		return 'publish' === $status ? '' : sprintf( __( 'links to content with status “%s” (not publicly visible)', 'colorbox-wp-mcp' ), $status );
	}

	$args     = array(
		'timeout'     => 6,
		'redirection' => 5,
		'user-agent'  => 'Mozilla/5.0 (colorbox-wp-mcp link check)',
	);
	$response = wp_safe_remote_head( $url, $args );
	$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	if ( in_array( $code, array( 0, 403, 405, 501 ), true ) ) {
		$response = wp_safe_remote_get( $url, $args + array( 'limit_response_size' => 65536 ) );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	}
	if ( is_wp_error( $response ) ) {
		/* translators: %s: connection error message */
		return sprintf( __( 'connection error: %s', 'colorbox-wp-mcp' ), $response->get_error_message() );
	}
	if ( 404 === $code && preg_match( '#^https://github\.com/[^/]+/[^/]+#', $url ) ) {
		return __( 'HTTP 404: the GitHub repository is private or does not exist; site visitors will not see it', 'colorbox-wp-mcp' );
	}
	return $code >= 400 ? 'HTTP ' . $code : '';
}
