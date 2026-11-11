<?php
/**
 * Obrazki: import do biblioteki mediów z repozytorium GitHub, z publicznego adresu albo z danych base64.
 *
 * Pliki pobiera serwer strony, więc agent nie musi przenosić bajtów obrazka przez model (to by było drogie
 * i zawodne). Dozwolone tylko JPEG, PNG, WebP i GIF (bez SVG — może zawierać skrypty). Ten sam plik
 * zaimportowany drugi raz zwraca istniejący załącznik (rozpoznanie po SHA-256 treści).
 */

namespace Colorbox\WpMcp\Media;

use Colorbox\WpMcp\Content;
use WP_Error;

defined( 'ABSPATH' ) || exit;

const MAX_BYTES         = 30 * 1024 * 1024;
const DEFAULT_BASE64_MB = 4;
const OPTION_BASE64_MB  = 'colorbox_mcp_base64_mb';
const OPTION_GH_TOKEN   = 'colorbox_mcp_github_token';

const META_SHA         = '_colorbox_sha256';
const META_SOURCE      = '_colorbox_source';
const ALLOWED_MIME     = array(
	'image/jpeg' => 'jpg',
	'image/png'  => 'png',
	'image/webp' => 'webp',
	'image/gif'  => 'gif',
);

/*
 * Token GitHub (fine-grained, tylko odczyt zawartości wybranych repo). W bazie zaszyfrowany kluczem
 * z soli wp-config, więc sam zrzut bazy (np. kopia nocna) go nie ujawnia.
 */

function crypto_key(): string {
	return hash( 'sha256', 'colorbox-mcp-github|' . AUTH_KEY . SECURE_AUTH_KEY, true );
}

function set_github_token( string $token ): void {
	$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	$box   = sodium_crypto_secretbox( $token, $nonce, crypto_key() );
	update_option( OPTION_GH_TOKEN, base64_encode( $nonce . $box ), false );
}

function delete_github_token(): void {
	delete_option( OPTION_GH_TOKEN );
}

function get_github_token(): string {
	$raw = base64_decode( (string) get_option( OPTION_GH_TOKEN, '' ), true );
	if ( ! $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
		return '';
	}
	$plain = sodium_crypto_secretbox_open(
		substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
		substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
		crypto_key()
	);
	return false === $plain ? '' : $plain;
}

/**
 * @return array<string, string>
 */
function github_headers( string $accept ): array {
	$headers = array(
		'Accept'               => $accept,
		'User-Agent'           => 'colorbox-wp-mcp',
		'X-GitHub-Api-Version' => '2022-11-28',
	);
	$token = get_github_token();
	if ( '' !== $token ) {
		$headers['Authorization'] = 'Bearer ' . $token;
	}
	return $headers;
}

/**
 * @return string|WP_Error Ścieżka API `repos/{owner}/{repo}/contents/{path}`.
 */
function github_contents_url( string $repo, string $path, string $ref ) {
	if ( ! preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ) {
		return new WP_Error( 'colorbox_bad_repo', __( 'Give the repository as "owner/name", e.g. "octocat/hello-world".', 'colorbox-wp-mcp' ) );
	}
	$path = trim( $path, '/' );
	if ( str_contains( $path, '..' ) ) {
		return new WP_Error( 'colorbox_bad_path', __( 'Path not allowed.', 'colorbox-wp-mcp' ) );
	}
	$url = 'https://api.github.com/repos/' . $repo . '/contents/' . implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	if ( '' !== $ref ) {
		$url = add_query_arg( 'ref', rawurlencode( $ref ), $url );
	}
	return $url;
}

function github_error( $response, string $repo ): WP_Error {
	$code = wp_remote_retrieve_response_code( $response );
	if ( 404 === $code || 403 === $code || 401 === $code ) {
		$hint = '' === get_github_token()
			? __( 'The server has no GitHub token; a private repository requires one (Settings → MCP Agent → GitHub, or wp colorbox-mcp github-token set).', 'colorbox-wp-mcp' )
			: __( 'The GitHub token may not cover this repository.', 'colorbox-wp-mcp' );
		/* translators: 1: repository (owner/name), 2: HTTP status code, 3: hint sentence */
		return new WP_Error( 'colorbox_github_access', sprintf( __( 'GitHub: no access, or no such path in %1$s (HTTP %2$d). %3$s', 'colorbox-wp-mcp' ), $repo, $code, $hint ) );
	}
	/* translators: %d: HTTP status code */
	return new WP_Error( 'colorbox_github_error', sprintf( __( 'GitHub returned HTTP %d.', 'colorbox-wp-mcp' ), $code ) );
}

/**
 * Lista obrazków w katalogu repo (bez podkatalogów — te są wypisane osobno).
 *
 * @param array<string, mixed> $in repo, path, ref
 * @return array<string, mixed>|WP_Error
 */
function list_repo_images( array $in ) {
	$repo = (string) $in['repo'];
	$url  = github_contents_url( $repo, (string) ( $in['path'] ?? '' ), (string) ( $in['ref'] ?? '' ) );
	if ( is_wp_error( $url ) ) {
		return $url;
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 15,
			'headers' => github_headers( 'application/vnd.github+json' ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return github_error( $response, $repo );
	}

	$items = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $items ) || ! isset( $items[0] ) ) {
		return new WP_Error( 'colorbox_not_dir', __( 'This path is a file, not a folder. Give a folder, or use import-image directly.', 'colorbox-wp-mcp' ) );
	}

	$images = array();
	$dirs   = array();
	foreach ( $items as $item ) {
		if ( 'dir' === $item['type'] ) {
			$dirs[] = $item['path'];
		} elseif ( 'file' === $item['type'] && preg_match( '/\.(jpe?g|png|webp|gif)$/i', $item['name'] ) ) {
			$images[] = array(
				'path' => $item['path'],
				'size' => (int) $item['size'],
			);
		}
	}

	return array(
		'repo'        => $repo,
		'images'      => $images,
		'directories' => $dirs,
	);
}

/**
 * @return array{bytes:string,name:string,source:string}|WP_Error
 */
function fetch_from_github( array $gh ) {
	$repo = (string) ( $gh['repo'] ?? '' );
	$path = (string) ( $gh['path'] ?? '' );
	$url  = github_contents_url( $repo, $path, (string) ( $gh['ref'] ?? '' ) );
	if ( is_wp_error( $url ) ) {
		return $url;
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout'             => 30,
			'headers'             => github_headers( 'application/vnd.github.raw' ),
			'limit_response_size' => MAX_BYTES + 1,
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return github_error( $response, $repo );
	}

	return array(
		'bytes'  => wp_remote_retrieve_body( $response ),
		'name'   => basename( $path ),
		'source' => 'github:' . $repo . ( empty( $gh['ref'] ) ? '' : '@' . $gh['ref'] ) . ':' . trim( $path, '/' ),
	);
}

/**
 * Publiczny adres https. wp_safe_remote_get odrzuca adresy prywatne (LAN, localhost), więc narzędzie
 * nie da się użyć do zaglądania w sieć domową.
 *
 * @return array{bytes:string,name:string,source:string}|WP_Error
 */
function fetch_from_url( string $url ) {
	if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
		return new WP_Error( 'colorbox_bad_url', __( 'Only https:// URLs are allowed.', 'colorbox-wp-mcp' ) );
	}
	$response = wp_safe_remote_get(
		$url,
		array(
			'timeout'             => 30,
			'redirection'         => 3,
			'limit_response_size' => MAX_BYTES + 1,
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		/* translators: %d: HTTP status code */
		return new WP_Error( 'colorbox_download_failed', sprintf( __( 'Download failed (HTTP %d).', 'colorbox-wp-mcp' ), $code ) );
	}
	return array(
		'bytes'  => wp_remote_retrieve_body( $response ),
		'name'   => basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ),
		'source' => 'url:' . $url,
	);
}

/**
 * Największy limit base64 (MB), jaki zniesie ten serwer. Base64 jest o ~33% większe od pliku i przychodzi
 * w treści zapytania, więc musi się zmieścić w post_max_size (z zapasem na resztę JSON-a).
 */
function base64_server_max_mb(): int {
	$post = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
	$max  = $post > 0 ? (int) floor( $post * 0.7 / MB_IN_BYTES ) : PHP_INT_MAX;
	return max( 1, min( (int) ( MAX_BYTES / MB_IN_BYTES ), $max ) );
}

/** Limit base64 (MB) ustawiony w panelu, przycięty do możliwości serwera. */
function base64_limit_mb(): int {
	$mb = (int) get_option( OPTION_BASE64_MB, DEFAULT_BASE64_MB );
	return max( 1, min( base64_server_max_mb(), $mb ) );
}

/**
 * @return array{bytes:string,name:string,source:string}|WP_Error
 */
function from_base64( string $data, string $filename ) {
	if ( preg_match( '#^data:[^;]+;base64,#', $data, $m ) ) {
		$data = substr( $data, strlen( $m[0] ) );
	}
	$bytes = base64_decode( $data, true );
	if ( false === $bytes ) {
		return new WP_Error( 'colorbox_bad_base64', __( 'Invalid base64 data.', 'colorbox-wp-mcp' ) );
	}
	$limit = base64_limit_mb();
	if ( strlen( $bytes ) > $limit * MB_IN_BYTES ) {
		/* translators: %d: size limit in MB */
		return new WP_Error( 'colorbox_too_big', sprintf( __( 'base64 data is limited to %d MB. Import larger files from GitHub or from a URL.', 'colorbox-wp-mcp' ), $limit ) );
	}
	return array(
		'bytes'  => $bytes,
		'name'   => $filename,
		'source' => 'base64',
	);
}

/**
 * @param array<string, mixed> $in
 * @return array<string, mixed>|WP_Error
 */
function import_image( array $in ) {
	$sources = array_filter( array( 'github', 'url', 'data_base64' ), static fn( $k ) => ! empty( $in[ $k ] ) );
	if ( 1 !== count( $sources ) ) {
		return new WP_Error( 'colorbox_one_source', __( 'Give exactly one source: github, url or data_base64.', 'colorbox-wp-mcp' ) );
	}

	if ( ! empty( $in['github'] ) ) {
		$file = fetch_from_github( (array) $in['github'] );
	} elseif ( ! empty( $in['url'] ) ) {
		$file = fetch_from_url( (string) $in['url'] );
	} else {
		$file = from_base64( (string) $in['data_base64'], (string) ( $in['filename'] ?? 'image' ) );
	}
	if ( is_wp_error( $file ) ) {
		return $file;
	}
	return import_bytes( $file, $in );
}

/**
 * Zapisuje pobrany plik w bibliotece mediów: sprawdza typ, wykrywa duplikat, ustawia alt i podpis.
 * Wspólne dla wszystkich źródeł; inne wtyczki mogą tego użyć dla własnego źródła obrazków.
 *
 * @param array{bytes:string,name:string,source:string} $file
 * @param array<string, mixed>                          $in   post_id, title, alt, caption.
 * @return array<string, mixed>|WP_Error
 */
function import_bytes( array $file, array $in ) {
	$post_id = (int) ( $in['post_id'] ?? 0 );
	if ( $post_id ) {
		$post = Content\get_post_or_error( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'colorbox_forbidden', __( 'You are not allowed to edit this post.', 'colorbox-wp-mcp' ) );
		}
	}

	$bytes = $file['bytes'];
	if ( strlen( $bytes ) > MAX_BYTES ) {
		return new WP_Error( 'colorbox_too_big', __( 'The file is larger than 30 MB.', 'colorbox-wp-mcp' ) );
	}

	$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	$mime = is_array( $info ) ? (string) $info['mime'] : '';
	if ( ! isset( ALLOWED_MIME[ $mime ] ) ) {
		return new WP_Error( 'colorbox_not_image', __( 'This is not a JPEG, PNG, WebP or GIF image.', 'colorbox-wp-mcp' ) );
	}

	$sha      = hash( 'sha256', $bytes );
	$existing = get_posts(
		array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'meta_key'    => META_SHA, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'  => $sha, // phpcs:ignore WordPress.DB.SlowDBQuery
			'numberposts' => 1,
			'fields'      => 'ids',
		)
	);
	if ( $existing ) {
		$id = (int) $existing[0];
		update_alt_and_caption( $id, $in );
		return describe_attachment( $id, true );
	}

	// Nazwa pliku: z rozszerzeniem zgodnym z prawdziwym typem, nie z tym, co twierdzi źródło.
	$base = sanitize_file_name( pathinfo( $file['name'], PATHINFO_FILENAME ) );
	$name = ( '' !== $base ? $base : 'image' ) . '.' . ALLOWED_MIME[ $mime ];

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$tmp = wp_tempnam( $name );
	file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	$title = isset( $in['title'] ) ? sanitize_text_field( (string) $in['title'] ) : null;
	$id    = media_handle_sideload(
		array(
			'name'     => $name,
			'tmp_name' => $tmp,
		),
		$post_id,
		$title
	);
	if ( file_exists( $tmp ) ) {
		wp_delete_file( $tmp );
	}
	if ( is_wp_error( $id ) ) {
		return $id;
	}

	update_post_meta( $id, META_SHA, $sha );
	update_post_meta( $id, META_SOURCE, $file['source'] );
	update_alt_and_caption( $id, $in );

	return describe_attachment( $id, false );
}

function update_alt_and_caption( int $id, array $in ): void {
	if ( isset( $in['alt'] ) ) {
		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $in['alt'] ) );
	}
	if ( isset( $in['caption'] ) ) {
		wp_update_post(
			array(
				'ID'           => $id,
				'post_excerpt' => sanitize_text_field( (string) $in['caption'] ),
			)
		);
	}
}

/**
 * Opis załącznika z gotowym blokiem obrazka do wklejenia w treść wpisu.
 *
 * @return array<string, mixed>
 */
function describe_attachment( int $id, bool $reused ): array {
	$alt     = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
	$caption = wp_get_attachment_caption( $id );
	$size    = image_get_intermediate_size( $id, 'large' ) ? 'large' : 'full';
	$src     = (string) wp_get_attachment_image_url( $id, $size );

	$figcaption = $caption ? '<figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption>' : '';
	$block      = sprintf(
		'<!-- wp:image {"id":%1$d,"sizeSlug":"%2$s","linkDestination":"none"} --><figure class="wp-block-image size-%2$s"><img src="%3$s" alt="%4$s" class="wp-image-%1$d"/>%5$s</figure><!-- /wp:image -->',
		$id,
		$size,
		esc_url( $src ),
		esc_attr( $alt ),
		$figcaption
	);

	$meta = wp_get_attachment_metadata( $id );

	return array(
		'id'            => $id,
		'reused'        => $reused,
		'url'           => (string) wp_get_attachment_url( $id ),
		'width'         => (int) ( $meta['width'] ?? 0 ),
		'height'        => (int) ( $meta['height'] ?? 0 ),
		'alt'           => $alt,
		'source'        => (string) get_post_meta( $id, META_SOURCE, true ),
		'image_block'   => $block,
		'how_to_use'    => __( 'Insert image_block into the post content (update-post, content field) or set the ID as featured_image.', 'colorbox-wp-mcp' ),
	);
}

/**
 * @return true|WP_Error
 */
function validate_featured_image( int $id ) {
	if ( 0 === $id ) {
		return true; // 0 = usuń obrazek wyróżniający.
	}
	if ( ! wp_attachment_is_image( $id ) ) {
		/* translators: %d: attachment ID */
		return new WP_Error( 'colorbox_bad_featured', sprintf( __( 'Attachment %d does not exist or is not an image. Use import-image first.', 'colorbox-wp-mcp' ), $id ) );
	}
	return true;
}
