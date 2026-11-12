<?php
/**
 * Dokument Markdown z repozytorium GitHub (np. README.md) jako zwykłe bloki Gutenberga: nagłówki, akapity, listy,
 * kod, tabele, cytaty i obrazki. Ref jest przypinany do pełnego SHA, jak w github-code, więc wpis nie zmienia się
 * razem z repo. Obrazki z repo trafiają do biblioteki mediów; linki do innych plików repo wskazują na GitHub
 * (repo publiczne) albo znikają, zostaje tekst (repo prywatne — odwiedzający dostałby 404).
 *
 * Parser jest celowo mały (podzbiór CommonMark + tabele GitHuba). Czego nie umie bezpiecznie zamienić — surowy HTML,
 * znaczki (badge), SVG — pomija i wypisuje w „skipped”, żeby agent mógł to uzupełnić ręcznie.
 */

namespace Colorbox\WpMcp\Document;

use Colorbox\WpMcp\Code;
use Colorbox\WpMcp\Content;
use Colorbox\WpMcp\Media;
use WP_Error;

defined( 'ABSPATH' ) || exit;

const MAX_BYTES  = 300 * 1024;
const MAX_IMAGES = 20;

/** Aliasy języków z bloków ``` → nazwy highlight.js (jak w github-code). */
const FENCE_LANGUAGES = array(
	''              => 'plaintext',
	'text'          => 'plaintext',
	'txt'           => 'plaintext',
	'plain'         => 'plaintext',
	'c++'           => 'cpp',
	'arduino'       => 'cpp',
	'shell'         => 'bash',
	'zsh'           => 'bash',
	'console'       => 'bash',
	'shell-session' => 'bash',
	'python3'       => 'python',
	'jsonc'         => 'json',
	'svg'           => 'xml',
);

/**
 * @param array<string, mixed> $in repo, path?, ref?, lang?, post_id?, heading_offset?
 * @return array<string, mixed>|WP_Error
 */
function github_document( array $in ) {
	$repo = (string) ( $in['repo'] ?? '' );
	$path = trim( (string) ( $in['path'] ?? '' ), '/' );
	$path = '' !== $path ? $path : 'README.md';

	$check = Media\github_contents_url( $repo, $path, '' );
	if ( is_wp_error( $check ) ) {
		return $check;
	}
	if ( ! preg_match( '/\.(md|markdown)$/i', $path ) ) {
		return new WP_Error( 'colorbox_not_markdown', __( 'Only Markdown files (.md, .markdown) can be imported as a document. For code use colorbox-github-code.', 'colorbox-wp-mcp' ) );
	}

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

	$info = Code\github_json( 'https://api.github.com/repos/' . $repo, $repo );
	if ( is_wp_error( $info ) ) {
		return $info;
	}
	$repo    = (string) $info['full_name'];
	$private = ! empty( $info['private'] );
	$sha     = Code\resolve_sha( $repo, (string) ( $in['ref'] ?? '' ), $info );
	if ( is_wp_error( $sha ) ) {
		return $sha;
	}

	$response = wp_remote_get(
		Media\github_contents_url( $repo, $path, $sha ),
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
	$markdown = wp_remote_retrieve_body( $response );
	if ( strlen( $markdown ) > MAX_BYTES ) {
		/* translators: %d: size limit in KB */
		return new WP_Error( 'colorbox_too_big', sprintf( __( 'The document is larger than %d KB. Import a smaller file.', 'colorbox-wp-mcp' ), MAX_BYTES / 1024 ) );
	}
	if ( str_contains( $markdown, "\0" ) || ! mb_check_encoding( $markdown, 'UTF-8' ) ) {
		return new WP_Error( 'colorbox_binary', __( 'This is not a UTF-8 text file.', 'colorbox-wp-mcp' ) );
	}

	$converter = new Converter(
		array(
			'repo'           => $repo,
			'sha'            => $sha,
			'path'           => $path,
			'private'        => $private,
			'post_id'        => $post_id,
			'heading_offset' => max( 0, min( 4, (int) ( $in['heading_offset'] ?? 0 ) ) ),
		)
	);
	$blocks = $converter->convert( $markdown );
	if ( '' === trim( $blocks ) ) {
		return new WP_Error( 'colorbox_empty_document', __( 'Nothing to import: the document is empty or contains only things that were skipped.', 'colorbox-wp-mcp' ) );
	}
	$source = Code\source_block( $repo, $path, $sha, $private, (string) ( $in['lang'] ?? 'pl' ) );

	return array(
		'repo'         => $repo,
		'path'         => $path,
		'sha'          => $sha,
		'private'      => $private,
		'title'        => $converter->title,
		'images'       => $converter->images,
		'skipped'      => $converter->skipped,
		'blocks'       => $blocks . $source,
		'source_block' => $source,
		'how_to_use'   => __( 'Insert "blocks" into the post content with update-post (content field). The first level-1 heading is not included: use "title" for the post title if it fits. Review "skipped" and add what is missing by hand.', 'colorbox-wp-mcp' ),
	);
}

/**
 * Markdown → bloki. Jeden obiekt na jeden dokument (trzyma licznik obrazków i listę pominiętych rzeczy).
 */
final class Converter {

	/** @var string Tekst pierwszego nagłówka H1 (tytuł dokumentu), pusty gdy go nie było. */
	public $title = '';

	/** @var array<int, array<string, mixed>> Zaimportowane obrazki. */
	public $images = array();

	/** @var string[] Co pominięto i dlaczego. */
	public $skipped = array();

	/** @var array<string, mixed> */
	private $ctx;

	/** @var array<string, string> Definicje linków „[ref]: url”. */
	private $refs = array();

	/** @var string[] Wstawki inline (kod, linki, tagi) podmieniane na znaczniki na czas escapowania. */
	private $slots = array();

	/** @var array<string, string> Pobrane obrazki: adres → blok (jeden plik, jeden import). */
	private $done = array();

	/** @var bool Czy już zgłoszono usunięcie linków (repo prywatne). */
	private $private_links_noted = false;

	public function __construct( array $ctx ) {
		$this->ctx = $ctx;
	}

	public function convert( string $markdown ): string {
		$text = str_replace( array( "\r\n", "\r", "\t" ), array( "\n", "\n", '    ' ), $markdown );
		$text = preg_replace( '/^---\n.*?\n---\n/s', '', $text, 1 ); // Front matter.
		$text = preg_replace( '/<!--.*?-->/s', '', $text );
		$lines = explode( "\n", $text );

		// Definicje linków referencyjnych zbieramy z góry i wycinamy z tekstu.
		foreach ( $lines as $k => $line ) {
			if ( preg_match( '/^ {0,3}\[([^\]]+)\]:\s*<?(\S+?)>?(?:\s+["\'(].*["\')])?\s*$/', $line, $m ) ) {
				$this->refs[ strtolower( $m[1] ) ] = $m[2];
				unset( $lines[ $k ] );
			}
		}
		return $this->blocks( array_values( $lines ), true );
	}

	/**
	 * @param string[] $lines
	 */
	private function blocks( array $lines, bool $top ): string {
		$out = '';
		$n   = count( $lines );
		$i   = 0;
		while ( $i < $n ) {
			$line = $lines[ $i ];
			if ( '' === trim( $line ) ) {
				++$i;
				continue;
			}

			// Blok kodu ``` albo ~~~.
			if ( preg_match( '/^( {0,3})(`{3,}|~{3,})\s*([^\s`{]*)/', $line, $m ) ) {
				$fence = $m[2];
				$code  = array();
				for ( ++$i; $i < $n; ++$i ) {
					if ( preg_match( '/^ {0,3}' . preg_quote( $fence[0], '/' ) . '{' . strlen( $fence ) . ',}\s*$/', $lines[ $i ] ) ) {
						++$i;
						break;
					}
					$code[] = preg_replace( '/^ {0,' . strlen( $m[1] ) . '}/', '', $lines[ $i ] );
				}
				$out .= $this->code_block( implode( "\n", $code ), $m[3] );
				continue;
			}

			// Nagłówek #.
			if ( preg_match( '/^ {0,3}(#{1,6})\s+(.*?)(?:\s+#+)?\s*$/', $line, $m ) ) {
				$out .= $this->heading( strlen( $m[1] ), $m[2], $top );
				++$i;
				continue;
			}

			// Linia pozioma.
			if ( preg_match( '/^ {0,3}([-*_])( *\1){2,} *$/', $line ) ) {
				$out .= '<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->';
				++$i;
				continue;
			}

			// Tabela: wiersz z | i pod nim wiersz z kreskami.
			if ( str_contains( $line, '|' ) && isset( $lines[ $i + 1 ] ) && preg_match( '/^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/', $lines[ $i + 1 ] ) ) {
				$rows = array( $line );
				for ( $i += 2; $i < $n && '' !== trim( $lines[ $i ] ) && str_contains( $lines[ $i ], '|' ); ++$i ) {
					$rows[] = $lines[ $i ];
				}
				$out .= $this->table( $rows );
				continue;
			}

			// Cytat.
			if ( preg_match( '/^ {0,3}>/', $line ) ) {
				$inner = array();
				for ( ; $i < $n && preg_match( '/^ {0,3}> ?(.*)$/', $lines[ $i ], $q ); ++$i ) {
					$inner[] = $q[1];
				}
				$out .= $this->quote( $inner );
				continue;
			}

			// Lista.
			if ( $this->marker( $line ) ) {
				$deferred = array();
				$out     .= $this->list_block( $lines, $i, $deferred );
				$out     .= implode( '', $deferred );
				continue;
			}

			// Blok HTML (do pustej linii).
			if ( preg_match( '/^ {0,3}<(\/?[a-zA-Z][a-zA-Z0-9-]*)/', $line ) ) {
				$html = array();
				for ( ; $i < $n && '' !== trim( $lines[ $i ] ); ++$i ) {
					$html[] = $lines[ $i ];
				}
				$out .= $this->html_block( implode( "\n", $html ), $top );
				continue;
			}

			// Akapit — do pustej linii albo początku innego bloku. Podkreślenie === / --- robi z niego nagłówek.
			$para = array( $line );
			for ( ++$i; $i < $n; ++$i ) {
				$next = $lines[ $i ];
				if ( preg_match( '/^ {0,3}(=+|-+)\s*$/', $next, $s ) ) {
					$out .= $this->heading( '=' === $s[1][0] ? 1 : 2, implode( ' ', array_map( 'trim', $para ) ), $top );
					$para = array();
					++$i;
					break;
				}
				if ( '' === trim( $next ) || $this->starts_block( $next ) ) {
					break;
				}
				$para[] = $next;
			}
			if ( $para ) {
				$out .= $this->paragraph( $para );
			}
		}
		return $out;
	}

	/** Czy linia zaczyna nowy blok (przerywa akapit). */
	private function starts_block( string $line ): bool {
		return (bool) preg_match( '/^ {0,3}(#{1,6}\s|`{3,}|~{3,}|>|<[a-zA-Z\/])/', $line )
			|| (bool) preg_match( '/^ {0,3}([-*_])( *\1){2,} *$/', $line )
			|| (bool) preg_match( '/^ {0,3}([-*+]|1[.)])\s+\S/', $line );
	}

	/**
	 * @return array{0:int,1:bool,2:int,3:string,4:int}|null wcięcie, numerowana?, wcięcie treści, tekst, numer startu
	 */
	private function marker( string $line ): ?array {
		if ( ! preg_match( '/^( *)([-*+]|(\d{1,9})[.)])( +|$)(.*)$/', $line, $m ) ) {
			return null;
		}
		$ordered = '' !== $m[3];
		$pad     = strlen( $m[4] );
		return array( strlen( $m[1] ), $ordered, strlen( $m[1] ) + strlen( $m[2] ) + ( $pad > 4 ? 1 : max( 1, $pad ) ), $m[5], $ordered ? (int) $m[3] : 1 );
	}

	private function indent( string $line ): int {
		return strlen( $line ) - strlen( ltrim( $line, ' ' ) );
	}

	/**
	 * Lista od linii $i (przesuwa $i za listę). Bloki kodu i obrazki z wnętrza punktów trafiają do $deferred,
	 * bo blok punktu listy w WordPressie przyjmuje tylko tekst i zagnieżdżoną listę.
	 *
	 * @param string[] $lines
	 * @param string[] $deferred
	 */
	private function list_block( array $lines, int &$i, array &$deferred ): string {
		$first = $this->marker( $lines[ $i ] );
		$base  = $first[0];
		$items = array();
		$n     = count( $lines );
		$blank = false;

		while ( $i < $n ) {
			$line = $lines[ $i ];
			if ( '' === trim( $line ) ) {
				$blank = true;
				++$i;
				continue;
			}
			$m   = $this->marker( $line );
			$cur = $items ? count( $items ) - 1 : -1;
			if ( $m && $m[0] < ( $cur >= 0 ? $items[ $cur ]['content'] : $base + 1 ) && $m[0] >= $base - 1 ) {
				if ( $m[1] !== $first[1] ) {
					break; // Inny rodzaj listy na tym samym poziomie = nowa lista.
				}
				$items[] = array(
					'text'    => array( $m[3] ),
					'content' => $m[2],
					'sub'     => array(),
				);
				$blank   = false;
				++$i;
				continue;
			}
			if ( $cur < 0 ) {
				break;
			}
			if ( $this->indent( $line ) >= $items[ $cur ]['content'] ) {
				$rest = substr( $line, $items[ $cur ]['content'] );
				if ( ! $blank && ! $items[ $cur ]['sub'] && ! $this->marker( $rest ) && ! $this->starts_block( $rest ) ) {
					$items[ $cur ]['text'][] = $rest; // Dalszy ciąg tekstu punktu, wcięty pod treścią.
					++$i;
					continue;
				}
				if ( $blank ) {
					$items[ $cur ]['sub'][] = '';
				}
				$items[ $cur ]['sub'][] = substr( $line, $items[ $cur ]['content'] );
				$blank                  = false;
				++$i;
				continue;
			}
			if ( ! $blank && ! $items[ $cur ]['sub'] && ! $this->starts_block( $line ) && $this->indent( $line ) < 4 ) {
				$items[ $cur ]['text'][] = $line; // Leniwa kontynuacja akapitu punktu.
				++$i;
				continue;
			}
			break;
		}

		$tag  = $first[1] ? 'ol' : 'ul';
		$attr = array();
		if ( $first[1] ) {
			$attr['ordered'] = true;
			if ( 1 !== $first[4] ) {
				$attr['start'] = $first[4];
			}
		}
		$html = '';
		foreach ( $items as $item ) {
			$images = array();
			$li     = $this->inline( $this->join( $item['text'] ), $images );
			$li     = preg_replace( array( '/^\[ \]\s*/', '/^\[[xX]\]\s*/' ), array( '☐ ', '☑ ' ), $li );
			$li    .= $this->list_children( $item['sub'], $deferred );
			foreach ( $images as $img ) {
				$deferred[] = $this->image( $img[0], $img[1] );
			}
			$html .= '<!-- wp:list-item --><li>' . $li . '</li><!-- /wp:list-item -->';
		}
		$open = '<' . $tag . ( isset( $attr['start'] ) ? ' start="' . (int) $attr['start'] . '"' : '' ) . ' class="wp-block-list">';
		return '<!-- wp:list' . ( $attr ? ' ' . wp_json_encode( $attr ) : '' ) . ' -->' . $open . $html . '</' . $tag . '><!-- /wp:list -->';
	}

	/**
	 * Wnętrze punktu listy: zagnieżdżone listy zostają w punkcie, dalsze akapity dochodzą po <br>,
	 * bloki kodu idą za listę.
	 *
	 * @param string[] $sub
	 * @param string[] $deferred
	 */
	private function list_children( array $sub, array &$deferred ): string {
		$out = '';
		$n   = count( $sub );
		for ( $k = 0; $k < $n; ) {
			$line = $sub[ $k ];
			if ( '' === trim( $line ) ) {
				++$k;
				continue;
			}
			if ( $this->marker( $line ) ) {
				$out .= $this->list_block( $sub, $k, $deferred );
				continue;
			}
			if ( preg_match( '/^ {0,3}(`{3,}|~{3,})\s*([^\s`{]*)/', $line ) ) {
				$chunk = array( $line );
				for ( ++$k; $k < $n; ++$k ) {
					$chunk[] = $sub[ $k ];
					if ( preg_match( '/^ {0,3}(`{3,}|~{3,})\s*$/', $sub[ $k ] ) ) {
						++$k;
						break;
					}
				}
				$deferred[] = $this->blocks( $chunk, false );
				continue;
			}
			$para = array();
			for ( ; $k < $n && '' !== trim( $sub[ $k ] ) && ( ! $para || ! $this->starts_block( $sub[ $k ] ) ); ++$k ) {
				$para[] = $sub[ $k ];
			}
			$images = array();
			// Po zagnieżdżonej liście nowy wiersz robi sama lista; przed nią trzeba złamania.
			$out .= ( str_ends_with( $out, '<!-- /wp:list -->' ) ? '' : '<br>' ) . $this->inline( $this->join( $para ), $images );
			foreach ( $images as $img ) {
				$deferred[] = $this->image( $img[0], $img[1] );
			}
		}
		return $out;
	}

	/**
	 * Linie akapitu w jeden tekst; dwie spacje albo \ na końcu linii = twarde złamanie.
	 *
	 * @param string[] $lines
	 */
	private function join( array $lines ): string {
		$parts = array();
		foreach ( $lines as $line ) {
			$hard    = (bool) preg_match( '/( {2,}|\\\\)$/', $line );
			$parts[] = trim( rtrim( $line, '\\' ) ) . ( $hard ? "\x03" : '' );
		}
		return implode( ' ', $parts );
	}

	private function heading( int $level, string $text, bool $top ): string {
		$images = array();
		$html   = trim( $this->inline( trim( $text ), $images ) );
		if ( 1 === $level && $top && '' === $this->title ) {
			$this->title = trim( wp_strip_all_tags( $html ) );
			/* translators: %s: heading text */
			$this->skipped[] = sprintf( __( 'Level-1 heading "%s" (the post has its own title).', 'colorbox-wp-mcp' ), $this->title );
			return '';
		}
		if ( '' === $html ) {
			return '';
		}
		$level = max( 2, min( 6, $level + $this->ctx['heading_offset'] ) );
		return '<!-- wp:heading' . ( 2 === $level ? '' : ' {"level":' . $level . '}' ) . ' --><h' . $level . ' class="wp-block-heading">' . $html . '</h' . $level . '><!-- /wp:heading -->';
	}

	/**
	 * @param string[] $lines
	 */
	private function paragraph( array $lines ): string {
		$images = array();
		$html   = trim( $this->inline( $this->join( $lines ), $images ) );
		$html   = preg_replace( '/^(<br>\s*)+|(\s*<br>)+$/', '', $html );
		$out    = '' !== trim( wp_strip_all_tags( $html ) ) ? '<!-- wp:paragraph --><p>' . $html . '</p><!-- /wp:paragraph -->' : '';
		foreach ( $images as $img ) {
			$out .= $this->image( $img[0], $img[1] );
		}
		return $out;
	}

	private function code_block( string $code, string $info ): string {
		$info     = strtolower( $info );
		$language = FENCE_LANGUAGES[ $info ] ?? ( Code\LANGUAGES[ $info ] ?? ( sanitize_key( $info ) ?: 'plaintext' ) );
		$class    = 'language-' . $language;
		return sprintf(
			'<!-- wp:code {"className":"%1$s"} --><pre class="wp-block-code %1$s"><code>%2$s</code></pre><!-- /wp:code -->',
			esc_attr( $class ),
			htmlspecialchars( $code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true )
		);
	}

	/**
	 * @param string[] $rows Pierwszy wiersz = nagłówek; wiersz z kreskami już pominięty.
	 */
	private function table( array $rows ): string {
		$html = '';
		foreach ( $rows as $r => $row ) {
			$row   = trim( str_replace( '\\|', "\x04", $row ) );
			$row   = preg_replace( array( '/^\|/', '/\|$/' ), '', $row );
			$cells = explode( '|', $row );
			$cell  = 0 === $r ? 'th' : 'td';
			$html .= ( 1 === $r ? '<tbody>' : '' ) . '<tr>';
			foreach ( $cells as $c ) {
				$images = array();
				$text   = $this->inline( trim( str_replace( "\x04", '|', $c ) ), $images );
				if ( $images ) {
					$this->skipped[] = __( 'Image inside a table cell (tables hold text only).', 'colorbox-wp-mcp' );
				}
				$html .= '<' . $cell . '>' . $text . '</' . $cell . '>';
			}
			$html .= '</tr>' . ( 0 === $r ? '</thead>' : '' );
		}
		$html = '<thead>' . $html . ( count( $rows ) > 1 ? '</tbody>' : '' );
		return '<!-- wp:table --><figure class="wp-block-table"><table class="has-fixed-layout">' . $html . '</table></figure><!-- /wp:table -->';
	}

	/**
	 * @param string[] $lines Linie cytatu bez „>”.
	 */
	private function quote( array $lines ): string {
		// Ostrzeżenia GitHuba: > [!NOTE] … → pogrubiony nagłówek w cytacie.
		if ( isset( $lines[0] ) && preg_match( '/^\s*\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]\s*$/i', $lines[0], $m ) ) {
			$labels   = array(
				'NOTE'      => __( 'Note', 'colorbox-wp-mcp' ),
				'TIP'       => __( 'Tip', 'colorbox-wp-mcp' ),
				'IMPORTANT' => __( 'Important', 'colorbox-wp-mcp' ),
				'WARNING'   => __( 'Warning', 'colorbox-wp-mcp' ),
				'CAUTION'   => __( 'Caution', 'colorbox-wp-mcp' ),
			);
			$lines[0] = '**' . $labels[ strtoupper( $m[1] ) ] . '**';
			array_splice( $lines, 1, 0, array( '' ) );
		}
		$inner = $this->blocks( $lines, false );
		return '' === $inner ? '' : '<!-- wp:quote --><blockquote class="wp-block-quote">' . $inner . '</blockquote><!-- /wp:quote -->';
	}

	/** Surowy HTML: wyciągamy obrazki i zwykły tekst; resztę (details, tabele HTML, skrypty…) pomijamy. */
	private function html_block( string $html, bool $top ): string {
		preg_match( '/^\s*<\/?([a-zA-Z][a-zA-Z0-9-]*)/', $html, $m );
		$tag = strtolower( $m[1] );
		$out = '';
		if ( in_array( $tag, array( 'p', 'div', 'center', 'a', 'img', 'picture', 'br', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
			$images = array();
			$this->html_images( $html, $images );
			$text = trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
			if ( '' !== $text ) {
				$out .= preg_match( '/^h([1-6])$/', $tag, $h ) ? $this->heading( (int) $h[1], $text, $top ) : $this->paragraph( array( $text ) );
			}
			foreach ( $images as $img ) {
				$out .= $this->image( $img[0], $img[1] );
			}
			return $out;
		}
		/* translators: 1: HTML tag name, 2: first characters of the HTML */
		$this->skipped[] = sprintf( __( 'Raw HTML <%1$s>: %2$s', 'colorbox-wp-mcp' ), $tag, mb_substr( preg_replace( '/\s+/', ' ', $html ), 0, 80 ) );
		return '';
	}

	/**
	 * @param array<int, array{0:string,1:string}> $images alt, src
	 */
	private function html_images( string $html, array &$images ): void {
		if ( preg_match_all( '/<img\b[^>]*>/i', $html, $all ) ) {
			foreach ( $all[0] as $img ) {
				$src = preg_match( '/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $img, $s ) ? $s[1] : '';
				$alt = preg_match( '/\balt\s*=\s*["\']([^"\']*)["\']/i', $img, $a ) ? html_entity_decode( $a[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
				if ( '' !== $src ) {
					$images[] = array( $alt, $src );
				}
			}
		}
	}

	/**
	 * Tekst inline → HTML. Obrazki nie zostają w tekście: trafiają do $images i potem jako osobne bloki.
	 *
	 * @param array<int, array{0:string,1:string}> $images
	 */
	private function inline( string $text, array &$images ): string {
		$slot = function ( string $html ): string {
			$this->slots[] = $html;
			return "\x01" . ( count( $this->slots ) - 1 ) . "\x02";
		};

		// Kod `…` — przed wszystkim innym, jego treść się nie formatuje.
		$text = preg_replace_callback( '/(`+)(.+?)\1/', static fn( $m ) => $slot( '<code>' . esc_html( trim( $m[2] ) ) . '</code>' ), $text );
		// Znaki ucieczki \* \_ itd.
		$text = preg_replace_callback( '/\\\\([\\\\`*_{}\[\]()#+\-.!|>~<])/', static fn( $m ) => $slot( esc_html( $m[1] ) ), $text );

		// Obrazki ![alt](src) i <img>.
		$text = preg_replace_callback(
			'/!\[([^\]]*)\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)/',
			static function ( $m ) use ( &$images ) {
				$images[] = array( $m[1], $m[2] );
				return '';
			},
			$text
		);
		$text = preg_replace_callback(
			'/!\[([^\]]*)\]\[([^\]]*)\]/',
			function ( $m ) use ( &$images ) {
				$key = strtolower( '' !== $m[2] ? $m[2] : $m[1] );
				if ( isset( $this->refs[ $key ] ) ) {
					$images[] = array( $m[1], $this->refs[ $key ] );
				}
				return '';
			},
			$text
		);
		$this->html_images( $text, $images );
		$text = preg_replace( '/<img\b[^>]*>/i', '', $text );

		// Linki [tekst](adres) i [tekst][ref]. Pusty tekst (po wycięciu obrazka w linku) = link znika.
		$link = function ( string $label, string $href ) use ( $slot ): string {
			if ( '' === trim( $label ) ) {
				return '';
			}
			$url = $this->resolve_link( $href );
			return null === $url ? $label : $slot( '<a href="' . esc_url( $url ) . '">' ) . $label . $slot( '</a>' );
		};
		$text = preg_replace_callback( '/\[([^\]]*)\]\(\s*<?([^)\s>]*)>?(?:\s+"[^"]*")?\s*\)/', static fn( $m ) => $link( $m[1], $m[2] ), $text );
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\[([^\]]*)\]/',
			function ( $m ) use ( $link ) {
				$key = strtolower( '' !== $m[2] ? $m[2] : $m[1] );
				return isset( $this->refs[ $key ] ) ? $link( $m[1], $this->refs[ $key ] ) : $m[0];
			},
			$text
		);
		// Adresy podane wprost: <https://…> i https://…
		$text = preg_replace_callback( '/<(https?:\/\/[^>\s]+)>/', static fn( $m ) => $slot( '<a href="' . esc_url( $m[1] ) . '">' . esc_html( $m[1] ) . '</a>' ), $text );
		$text = preg_replace_callback( '/(?<![\w"\'=\/\x02])https?:\/\/[^\s<>()\x01]+[^\s<>().,;:!?\'"\x01]/', static fn( $m ) => $slot( '<a href="' . esc_url( $m[0] ) . '">' . esc_html( $m[0] ) . '</a>' ), $text );

		// Proste znaczniki HTML zostają, reszta znika (tekst zostaje).
		$text = preg_replace_callback(
			'/<(\/?)(kbd|sub|sup|b|strong|i|em|code|s|del|mark|br)\s*\/?>/i',
			static fn( $m ) => $slot( '<' . $m[1] . strtolower( 'b' === strtolower( $m[2] ) ? 'strong' : ( 'i' === strtolower( $m[2] ) ? 'em' : $m[2] ) ) . '>' ),
			$text
		);
		$text = preg_replace( '/<\/?[a-zA-Z][^>]*>/', '', $text );

		$text = esc_html( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		$text = preg_replace( '/\*\*(?!\s)(.+?)(?<!\s)\*\*/', '<strong>$1</strong>', $text );
		$text = preg_replace( '/(?<![\w])__(?!\s)(.+?)(?<!\s)__(?![\w])/u', '<strong>$1</strong>', $text );
		$text = preg_replace( '/~~(?!\s)(.+?)(?<!\s)~~/', '<s>$1</s>', $text );
		$text = preg_replace( '/(?<![*\w])\*(?![\s*])(.+?)(?<![\s*])\*(?![*\w])/u', '<em>$1</em>', $text );
		$text = preg_replace( '/(?<![\w])_(?![\s_])(.+?)(?<![\s_])_(?![\w])/u', '<em>$1</em>', $text );
		$text = str_replace( "\x03", '<br>', $text );

		// Wstawki mogą zawierać inne wstawki (link wokół kodu), więc podmieniamy do skutku.
		for ( $guard = 0; $guard < 5 && str_contains( $text, "\x01" ); ++$guard ) {
			$text = preg_replace_callback( '/\x01(\d+)\x02/', fn( $m ) => $this->slots[ (int) $m[1] ], $text );
		}
		return trim( $text );
	}

	/** Adres linku: pełny zostaje, względny wskazuje plik w repo przypięty do SHA, kotwica i prywatne repo — bez linku. */
	private function resolve_link( string $href ): ?string {
		if ( '' === $href || '#' === $href[0] ) {
			return null;
		}
		if ( preg_match( '/^(https?:|mailto:)/i', $href ) ) {
			return $href;
		}
		if ( preg_match( '/^[a-z][a-z0-9+.-]*:/i', $href ) ) {
			return null;
		}
		$path = $this->repo_path( $href );
		if ( null === $path ) {
			return null;
		}
		if ( $this->ctx['private'] ) {
			if ( ! $this->private_links_noted ) {
				$this->skipped[]           = __( 'Links to other files of the private repository (only their text is kept; readers would get a 404).', 'colorbox-wp-mcp' );
				$this->private_links_noted = true;
			}
			return null;
		}
		$fragment = str_contains( $href, '#' ) ? '#' . explode( '#', $href, 2 )[1] : '';
		return Code\blob_url( $this->ctx['repo'], $this->ctx['sha'], $path ) . $fragment;
	}

	/** Względna ścieżka z dokumentu → ścieżka w repo; null gdy wychodzi poza repo. */
	private function repo_path( string $href ): ?string {
		$href  = rawurldecode( preg_replace( '/[?#].*$/', '', $href ) );
		$base  = '/' === ( $href[0] ?? '' ) ? array() : array_filter( explode( '/', dirname( $this->ctx['path'] ) ), static fn( $p ) => '' !== $p && '.' !== $p );
		$parts = array_values( $base );
		foreach ( explode( '/', $href ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				if ( ! $parts ) {
					return null;
				}
				array_pop( $parts );
				continue;
			}
			$parts[] = $part;
		}
		return $parts ? implode( '/', $parts ) : null;
	}

	/** Blok obrazka: import do biblioteki mediów (raz na adres) albo wpis w „skipped”. */
	private function image( string $alt, string $src ): string {
		$src = html_entity_decode( trim( $src ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( isset( $this->done[ $src ] ) ) {
			return $this->done[ $src ];
		}
		$host = strtolower( (string) wp_parse_url( $src, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $src, PHP_URL_PATH );
		if ( preg_match( '/(^|\.)(shields\.io|badgen\.net|badge\.fury\.io|codecov\.io|travis-ci\.(org|com)|circleci\.com)$/', $host ) || preg_match( '/badge(\.svg)?$/i', $path ) ) {
			/* translators: %s: image address */
			$this->skipped[] = sprintf( __( 'Badge: %s', 'colorbox-wp-mcp' ), $src );
			return $this->done[ $src ] = '';
		}
		if ( preg_match( '/\.svg$/i', $path ) ) {
			/* translators: %s: image address */
			$this->skipped[] = sprintf( __( 'SVG image (not allowed in the media library): %s', 'colorbox-wp-mcp' ), $src );
			return $this->done[ $src ] = '';
		}
		if ( count( $this->images ) >= MAX_IMAGES ) {
			/* translators: 1: image limit, 2: image address */
			$this->skipped[] = sprintf( __( 'Image over the limit of %1$d per document: %2$s', 'colorbox-wp-mcp' ), MAX_IMAGES, $src );
			return '';
		}

		if ( preg_match( '#^https?://#i', $src ) ) {
			$file = Media\fetch_from_url( $src );
		} else {
			$repo_path = $this->repo_path( $src );
			$file      = null === $repo_path
				? new WP_Error( 'colorbox_bad_path', __( 'Path not allowed.', 'colorbox-wp-mcp' ) )
				: Media\fetch_from_github(
					array(
						'repo' => $this->ctx['repo'],
						'path' => $repo_path,
						'ref'  => $this->ctx['sha'],
					)
				);
		}
		// Pusty alt nie nadpisuje opisu obrazka, który już jest w bibliotece.
		$result = is_wp_error( $file ) ? $file : Media\import_bytes( $file, array( 'post_id' => $this->ctx['post_id'] ) + ( '' !== $alt ? array( 'alt' => $alt ) : array() ) );
		if ( is_wp_error( $result ) ) {
			/* translators: 1: image address, 2: error message */
			$this->skipped[] = sprintf( __( 'Image %1$s: %2$s', 'colorbox-wp-mcp' ), $src, $result->get_error_message() );
			return $this->done[ $src ] = '';
		}
		$this->images[] = array(
			'src'    => $src,
			'id'     => $result['id'],
			'url'    => $result['url'],
			'alt'    => $alt,
			'reused' => $result['reused'],
		);
		if ( '' === $alt ) {
			/* translators: %s: image address */
			$this->skipped[] = sprintf( __( 'Image without alt text in the document: %s — add a description with update-image.', 'colorbox-wp-mcp' ), $src );
		}
		return $this->done[ $src ] = $result['image_block'];
	}
}
