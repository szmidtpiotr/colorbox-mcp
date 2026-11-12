<?php
/**
 * Fragment kodu z repozytorium GitHub jako blok core/code. Ref jest zawsze rozwiązywany do pełnego SHA,
 * więc wpis pokazuje kod z konkretnego commita i nie zmienia się razem z repo. Kolorowania wtyczka nie robi:
 * zostawia klasę language-<x>, którą rozpoznaje np. highlight.js albo Prism dodany do motywu.
 */

namespace Colorbox\WpMcp\Code;

use Colorbox\WpMcp\Media;
use WP_Error;

defined( 'ABSPATH' ) || exit;

const MAX_LINES = 400;
const MAX_BYTES = 1024 * 1024;

/** Rozszerzenie (albo pełna nazwa pliku) → język highlight.js. */
const LANGUAGES = array(
	'cpp'      => 'cpp',
	'cc'       => 'cpp',
	'cxx'      => 'cpp',
	'hpp'      => 'cpp',
	'h'        => 'cpp',
	'c'        => 'cpp',
	'ino'      => 'cpp',
	'py'       => 'python',
	'yaml'     => 'yaml',
	'yml'      => 'yaml',
	'sh'       => 'bash',
	'bash'     => 'bash',
	'js'       => 'javascript',
	'mjs'      => 'javascript',
	'ts'       => 'typescript',
	'json'     => 'json',
	'ini'      => 'ini',
	'toml'     => 'ini',
	'cfg'      => 'ini',
	'conf'     => 'ini',
	'makefile' => 'makefile',
	'mk'       => 'makefile',
	'diff'     => 'diff',
	'patch'    => 'diff',
	'php'      => 'php',
	'html'     => 'xml',
	'xml'      => 'xml',
	'css'      => 'css',
	'md'       => 'markdown',
	'rs'       => 'rust',
	'go'       => 'go',
	'sql'      => 'sql',
);

function detect_language( string $path ): string {
	$base = strtolower( basename( $path ) );
	if ( isset( LANGUAGES[ $base ] ) ) {
		return LANGUAGES[ $base ];
	}
	$ext = strtolower( pathinfo( $base, PATHINFO_EXTENSION ) );
	return LANGUAGES[ $ext ] ?? 'plaintext';
}

/**
 * @return array<string, mixed>|WP_Error
 */
function github_json( string $url, string $repo ) {
	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 15,
			'headers' => Media\github_headers( 'application/vnd.github+json' ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return Media\github_error( $response, $repo );
	}
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	return is_array( $data ) ? $data : new WP_Error( 'colorbox_github_error', __( 'GitHub returned an unreadable response.', 'colorbox-wp-mcp' ) );
}

/**
 * Gałąź, tag albo commit → pełny SHA. Pusty ref = gałąź domyślna repo.
 *
 * @param array<string, mixed> $info Odpowiedź API repos/{repo}.
 * @return string|WP_Error
 */
function resolve_sha( string $repo, string $ref, array $info ) {
	$ref    = '' !== $ref ? $ref : (string) $info['default_branch'];
	$commit = github_json( 'https://api.github.com/repos/' . $repo . '/commits/' . rawurlencode( $ref ), $repo );
	if ( is_wp_error( $commit ) || empty( $commit['sha'] ) ) {
		/* translators: 1: branch, tag or commit name, 2: repository (owner/name) */
		return new WP_Error( 'colorbox_bad_ref', sprintf( __( 'No branch, tag or commit "%1$s" found in %2$s.', 'colorbox-wp-mcp' ), $ref, $repo ) );
	}
	return (string) $commit['sha'];
}

/** Adres pliku na GitHubie przypięty do commita. */
function blob_url( string $repo, string $sha, string $path ): string {
	return 'https://github.com/' . $repo . '/blob/' . $sha . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
}

/**
 * Akapit „Źródło”/„Source” pod kodem albo dokumentem. Dla repo prywatnego bez linku (odwiedzający dostałby 404).
 * $start/$end = 0: cały plik.
 */
function source_block( string $repo, string $path, string $sha, bool $private, string $lang, int $start = 0, int $end = 0 ): string {
	$en         = 'en' === $lang;
	$label      = $en ? 'Source' : 'Źródło';
	$range      = $start ? sprintf( $en ? ', lines %d–%d' : ', linie %d–%d', $start, $end ) : '';
	$source_txt = esc_html( $repo . '/' . $path );

	if ( $private ) {
		$source_html = sprintf( '%s: %s%s', $label, $source_txt, esc_html( $range ) );
	} else {
		$anchor      = $start ? ( $start === $end ? '#L' . $start : '#L' . $start . '-L' . $end ) : '';
		$source_html = sprintf(
			'%1$s: <a href="%2$s">%3$s</a>%4$s (commit <code>%5$s</code>)',
			$label,
			esc_url( blob_url( $repo, $sha, $path ) . $anchor ),
			$source_txt,
			esc_html( $range ),
			esc_html( substr( $sha, 0, 7 ) )
		);
	}
	return '<!-- wp:paragraph {"className":"colorbox-code-source","fontSize":"small"} --><p class="colorbox-code-source has-small-font-size">' . $source_html . '</p><!-- /wp:paragraph -->';
}

/**
 * @param array<string, mixed> $in repo, path, ref?, start_line?, end_line?, language?, lang?
 * @return array<string, mixed>|WP_Error
 */
function github_code( array $in ) {
	$repo = (string) $in['repo'];
	$path = trim( (string) $in['path'], '/' );

	// Sprawdzenie ścieżki i nazwy repo (ta sama walidacja co przy obrazkach).
	$check = Media\github_contents_url( $repo, $path, '' );
	if ( is_wp_error( $check ) ) {
		return $check;
	}

	$info = github_json( 'https://api.github.com/repos/' . $repo, $repo );
	if ( is_wp_error( $info ) ) {
		return $info;
	}
	$private = ! empty( $info['private'] );

	$sha = resolve_sha( $repo, (string) ( $in['ref'] ?? '' ), $info );
	if ( is_wp_error( $sha ) ) {
		return $sha;
	}

	$url      = Media\github_contents_url( $repo, $path, $sha );
	$response = wp_remote_get(
		$url,
		array(
			'timeout'             => 20,
			'headers'             => Media\github_headers( 'application/vnd.github.raw' ),
			'limit_response_size' => MAX_BYTES + 1,
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return Media\github_error( $response, $repo );
	}
	$body = wp_remote_retrieve_body( $response );
	if ( strlen( $body ) > MAX_BYTES ) {
		return new WP_Error( 'colorbox_too_big', __( 'The file is larger than 1 MB. Point to a smaller file.', 'colorbox-wp-mcp' ) );
	}
	if ( str_contains( $body, "\0" ) || ! mb_check_encoding( $body, 'UTF-8' ) ) {
		return new WP_Error( 'colorbox_binary', __( 'This is not a UTF-8 text file.', 'colorbox-wp-mcp' ) );
	}

	$lines = preg_split( '/\r\n|\n|\r/', rtrim( $body, "\r\n" ) );
	$total = count( $lines );
	$start = max( 1, (int) ( $in['start_line'] ?? 1 ) );
	$end   = (int) ( $in['end_line'] ?? 0 );
	$end   = $end > 0 ? min( $end, $total ) : $total;
	if ( $start > $total ) {
		/* translators: %d: number of lines in the file */
		return new WP_Error( 'colorbox_bad_range', sprintf( __( 'The file has only %d lines.', 'colorbox-wp-mcp' ), $total ) );
	}
	if ( $end < $start ) {
		return new WP_Error( 'colorbox_bad_range', __( 'end_line is smaller than start_line.', 'colorbox-wp-mcp' ) );
	}
	if ( $end - $start + 1 > MAX_LINES ) {
		/* translators: 1: maximum number of lines, 2: number of lines requested */
		return new WP_Error( 'colorbox_too_long', sprintf( __( 'At most %1$d lines at a time (requested: %2$d). Give a narrower range.', 'colorbox-wp-mcp' ), MAX_LINES, $end - $start + 1 ) );
	}

	$code     = implode( "\n", array_slice( $lines, $start - 1, $end - $start + 1 ) );
	$language = '' !== (string) ( $in['language'] ?? '' ) ? sanitize_key( (string) $in['language'] ) : detect_language( $path );
	$class    = 'language-' . $language;

	// Podwójne kodowanie celowo: „&amp;” w kodzie ma się wyświetlić jako „&amp;”, nie jako „&”.
	$escaped    = htmlspecialchars( $code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true );
	$code_block = sprintf(
		'<!-- wp:code {"className":"%1$s"} --><pre class="wp-block-code %1$s"><code>%2$s</code></pre><!-- /wp:code -->',
		esc_attr( $class ),
		$escaped
	);

	$whole        = 1 === $start && $end === $total;
	$source_block = source_block( $repo, $path, $sha, $private, (string) ( $in['lang'] ?? 'pl' ), $whole ? 0 : $start, $whole ? 0 : $end );

	return array(
		'repo'         => $repo,
		'path'         => $path,
		'sha'          => $sha,
		'private'      => $private,
		'language'     => $language,
		'start_line'   => $start,
		'end_line'     => $end,
		'total_lines'  => $total,
		'code'         => $code,
		'code_block'   => $code_block,
		'source_block' => $source_block,
		'blocks'       => $code_block . $source_block,
		'how_to_use'   => __( 'Insert "blocks" into the post content with update-post (content field) where the code should appear.', 'colorbox-wp-mcp' ),
	);
}
