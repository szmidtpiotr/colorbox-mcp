<?php
/**
 * Operacje na wpisach, terminach i językach (Polylang). Wywoływane przez abilities.
 *
 * Zasady twarde: wpis powstaje zawsze jako szkic, status nigdy się nie zmienia, nic nie jest kasowane,
 * a poprawiać wolno tylko szkice i wpisy oczekujące na przegląd. Publikuje człowiek w panelu.
 */

namespace Colorbox\WpMcp\Content;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/** Statusy, które agent może poprawiać. Opublikowanych, zaplanowanych i prywatnych nie rusza. */
const EDITABLE_STATUSES = array( 'draft', 'pending', 'auto-draft' );

/** Typy treści obsługiwane przez narzędzia. */
const POST_TYPES = array( 'post', 'page' );

const META_SEO_DESCRIPTION = '_colorbox_seo_description';
const META_SOCIAL_IMAGE    = '_colorbox_social_image';
const META_REVIEW_NOTE     = '_colorbox_review_note';

/** Statusy widoczne w liście wpisów. */
const LISTABLE_STATUSES = array( 'draft', 'pending', 'publish', 'future', 'private' );

function polylang_ready(): bool {
	return function_exists( 'pll_languages_list' ) && function_exists( 'pll_set_post_language' );
}

/**
 * @return string[] Kody języków, np. ['pl', 'en'].
 */
function language_slugs(): array {
	return polylang_ready() ? (array) pll_languages_list( array( 'fields' => 'slug' ) ) : array();
}

function default_language(): string {
	return function_exists( 'pll_default_language' ) ? (string) pll_default_language() : '';
}

/**
 * @return true|WP_Error
 */
function validate_language( string $lang ) {
	if ( ! polylang_ready() ) {
		return new WP_Error( 'colorbox_no_polylang', __( 'Polylang is not active.', 'colorbox-wp-mcp' ) );
	}
	if ( ! in_array( $lang, language_slugs(), true ) ) {
		return new WP_Error(
			'colorbox_bad_language',
			sprintf(
				/* translators: 1: language code, 2: comma-separated list of available language codes */
				__( 'Unknown language "%1$s". Available: %2$s.', 'colorbox-wp-mcp' ),
				$lang,
				implode( ', ', language_slugs() )
			)
		);
	}
	return true;
}

/**
 * @return array<int, array<string, mixed>>
 */
function list_languages(): array {
	if ( ! polylang_ready() ) {
		return array();
	}
	$default = default_language();
	$out     = array();
	foreach ( (array) pll_languages_list( array( 'fields' => '' ) ) as $lang ) {
		$out[] = array(
			'slug'    => $lang->slug,
			'name'    => $lang->name,
			'locale'  => $lang->locale,
			'default' => $lang->slug === $default,
		);
	}
	return $out;
}

/**
 * @return WP_Post|WP_Error
 */
function get_post_or_error( int $id ) {
	$post = get_post( $id );
	if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, POST_TYPES, true ) ) {
		/* translators: %d: post ID */
		return new WP_Error( 'colorbox_not_found', sprintf( __( 'There is no post or page with ID %d.', 'colorbox-wp-mcp' ), $id ) );
	}
	return $post;
}

function post_language( int $id ): string {
	return function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $id, 'slug' ) : '';
}

/**
 * @return array<string, int>
 */
function post_translations( int $id ): array {
	return function_exists( 'pll_get_post_translations' ) ? array_map( 'intval', (array) pll_get_post_translations( $id ) ) : array();
}

/**
 * Krótki opis wpisu do list.
 *
 * @return array<string, mixed>
 */
function summarize_post( WP_Post $post ): array {
	return array(
		'id'           => $post->ID,
		'type'         => $post->post_type,
		'title'        => get_the_title( $post ),
		'status'       => $post->post_status,
		'lang'         => post_language( $post->ID ),
		'date'         => $post->post_date,
		// false = szkic bez przypiętej daty: WordPress nada mu datę dopiero w chwili publikacji.
		'date_fixed'   => has_fixed_date( $post ),
		'modified'     => $post->post_modified,
		'categories'   => 'post' === $post->post_type ? wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ) : array(),
		'parent'       => (int) $post->post_parent,
		'translations' => (object) post_translations( $post->ID ),
		'edit_url'     => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
	);
}

/**
 * Pełny wpis z treścią w blokach (surowy HTML z komentarzami bloków).
 *
 * @return array<string, mixed>
 */
function full_post( WP_Post $post ): array {
	$terms = static function ( string $taxonomy ) use ( $post ): array {
		$list = get_the_terms( $post->ID, $taxonomy );
		if ( ! is_array( $list ) ) {
			return array();
		}
		return array_map(
			static fn( $t ) => array(
				'id'   => $t->term_id,
				'name' => $t->name,
				'slug' => $t->slug,
			),
			$list
		);
	};

	return array_merge(
		summarize_post( $post ),
		array(
			'slug'          => $post->post_name,
			'excerpt'       => $post->post_excerpt,
			'content'       => $post->post_content,
			'category_list' => $terms( 'category' ),
			'tags'          => $terms( 'post_tag' ),
			'author'        => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'preview_url'   => get_preview_post_link( $post ),
			'editable'      => in_array( $post->post_status, EDITABLE_STATUSES, true ) && current_user_can( 'edit_post', $post->ID ),
			'featured_image' => (int) get_post_thumbnail_id( $post ),
			'seo_description' => (string) get_post_meta( $post->ID, META_SEO_DESCRIPTION, true ),
			'social_image'   => (int) get_post_meta( $post->ID, META_SOCIAL_IMAGE, true ),
			'review_note'    => (string) get_post_meta( $post->ID, META_REVIEW_NOTE, true ),
			'images'        => array_values( array_map( 'intval', wp_list_pluck( get_attached_media( 'image', $post->ID ), 'ID' ) ) ),
		)
	);
}

/**
 * @param array<string, mixed> $args
 * @return array<string, mixed>|WP_Error
 */
function list_posts( array $args ) {
	$per_page = max( 1, min( 50, (int) ( $args['per_page'] ?? 20 ) ) );
	$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

	$status = $args['status'] ?? '';
	if ( '' === $status || 'any' === $status ) {
		$status = LISTABLE_STATUSES;
	} elseif ( ! in_array( $status, LISTABLE_STATUSES, true ) ) {
		/* translators: %s: comma-separated list of status slugs */
		return new WP_Error( 'colorbox_bad_status', sprintf( __( 'Allowed statuses: %s, any.', 'colorbox-wp-mcp' ), implode( ', ', LISTABLE_STATUSES ) ) );
	}

	$type = (string) ( $args['type'] ?? 'post' );
	if ( 'any' === $type ) {
		$type = POST_TYPES;
	} elseif ( ! in_array( $type, POST_TYPES, true ) ) {
		return new WP_Error( 'colorbox_bad_type', __( 'Type: post, page or any.', 'colorbox-wp-mcp' ) );
	}

	$query = array(
		'post_type'           => $type,
		'post_status'         => $status,
		'posts_per_page'      => $per_page,
		'paged'               => $page,
		'orderby'             => 'modified',
		'order'               => 'DESC',
		'ignore_sticky_posts' => true,
		'lang'                => '', // Polylang: wszystkie języki, chyba że niżej podano konkretny.
	);

	if ( ! empty( $args['lang'] ) ) {
		$ok = validate_language( (string) $args['lang'] );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$query['lang'] = (string) $args['lang'];
	}

	if ( ! empty( $args['category'] ) ) {
		$cat = $args['category'];
		if ( is_numeric( $cat ) ) {
			$query['cat'] = (int) $cat;
		} else {
			$query['category_name'] = sanitize_title( (string) $cat );
		}
	}

	if ( ! empty( $args['search'] ) ) {
		$query['s'] = (string) $args['search'];
	}

	$q = new \WP_Query( $query );

	return array(
		'total'       => (int) $q->found_posts,
		'total_pages' => (int) $q->max_num_pages,
		'page'        => $page,
		'posts'       => array_map( __NAMESPACE__ . '\\summarize_post', $q->posts ),
	);
}

/**
 * @return array<int, array<string, mixed>>|WP_Error
 */
function list_categories( string $lang ) {
	$args = array(
		'taxonomy'   => 'category',
		'hide_empty' => false,
		'lang'       => '',
	);
	if ( '' !== $lang ) {
		$ok = validate_language( $lang );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$args['lang'] = $lang;
	}

	$terms = get_terms( $args );
	if ( is_wp_error( $terms ) ) {
		return $terms;
	}

	return array_map(
		static fn( $t ) => array(
			'id'           => $t->term_id,
			'name'         => $t->name,
			'slug'         => $t->slug,
			'parent'       => $t->parent,
			'count'        => $t->count,
			'lang'         => function_exists( 'pll_get_term_language' ) ? (string) pll_get_term_language( $t->term_id ) : '',
			'translations' => (object) ( function_exists( 'pll_get_term_translations' ) ? pll_get_term_translations( $t->term_id ) : array() ),
		),
		$terms
	);
}

/**
 * Zamienia listę ID kategorii na ID w danym języku. Jeśli kategoria jest w innym języku, bierze jej
 * tłumaczenie; jeśli tłumaczenia nie ma — błąd (agent tworzy kategorie tylko narzędziem create-category).
 *
 * @param array<int|string> $ids
 * @return int[]|WP_Error
 */
function resolve_categories( array $ids, string $lang ) {
	$out = array();
	foreach ( $ids as $id ) {
		$term = get_term( (int) $id, 'category' );
		if ( ! $term instanceof \WP_Term ) {
			/* translators: %s: category ID */
			return new WP_Error( 'colorbox_bad_category', sprintf( __( 'There is no category with ID %s. Use list-categories.', 'colorbox-wp-mcp' ), $id ) );
		}
		$term_lang = function_exists( 'pll_get_term_language' ) ? (string) pll_get_term_language( $term->term_id ) : '';
		if ( '' === $term_lang || $term_lang === $lang ) {
			$out[] = $term->term_id;
			continue;
		}
		$translated = function_exists( 'pll_get_term' ) ? (int) pll_get_term( $term->term_id, $lang ) : 0;
		if ( ! $translated ) {
			return new WP_Error(
				'colorbox_category_language',
				sprintf(
					/* translators: 1: category name, 2: category language code, 3: requested language code */
					__( 'Category "%1$s" (%2$s) has no version in language "%3$s". Ask the site owner to add it.', 'colorbox-wp-mcp' ),
					$term->name,
					$term_lang,
					$lang
				)
			);
		}
		$out[] = $translated;
	}
	return array_values( array_unique( $out ) );
}

/**
 * Tagi po nazwach, osobno dla każdego języka. Brakujący tag w danym języku jest tworzony.
 *
 * @param string[] $names
 * @return int[]|WP_Error
 */
function resolve_tags( array $names, string $lang ) {
	$out = array();
	foreach ( $names as $name ) {
		$name = trim( wp_strip_all_tags( (string) $name ) );
		if ( '' === $name ) {
			continue;
		}

		$existing = get_terms(
			array(
				'taxonomy'   => 'post_tag',
				'name'       => $name,
				'hide_empty' => false,
				'lang'       => $lang,
				'number'     => 1,
			)
		);
		if ( is_array( $existing ) && ! empty( $existing ) ) {
			$out[] = (int) $existing[0]->term_id;
			continue;
		}

		$slug = sanitize_title( $name );
		if ( term_exists( $slug, 'post_tag' ) ) {
			$slug .= '-' . $lang;
		}
		$created = wp_insert_term( $name, 'post_tag', array( 'slug' => $slug ) );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		if ( function_exists( 'pll_set_term_language' ) ) {
			pll_set_term_language( (int) $created['term_id'], $lang );
		}
		$out[] = (int) $created['term_id'];
	}
	return array_values( array_unique( $out ) );
}

/**
 * Czy wpis ma przypiętą datę. Szkic bez niej ma w bazie post_date_gmt = 0000-00-00 i przy każdym zapisie
 * dostaje post_date „teraz”, a przy publikacji — datę publikacji.
 */
function has_fixed_date( WP_Post $post ): bool {
	return '0000-00-00 00:00:00' !== $post->post_date_gmt && '' !== (string) $post->post_date_gmt;
}

/**
 * Data wpisu podana przez agenta, w czasie lokalnym strony: "YYYY-MM-DD HH:MM:SS" albo "YYYY-MM-DD HH:MM".
 * Zły format, nieistniejąca data i data w przyszłości to błąd — data w przyszłości oznaczałaby planowanie
 * publikacji, a publikuje człowiek.
 *
 * @param mixed $value
 * @return array{0: string, 1: string}|WP_Error [ post_date, post_date_gmt ]
 */
function parse_post_date( $value ) {
	$raw = is_string( $value ) ? trim( $value ) : '';
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $raw ) ) {
		return new WP_Error(
			'colorbox_bad_date',
			sprintf(
				/* translators: %s: the value received */
				__( 'Invalid date "%s". Use the site local time in the format YYYY-MM-DD HH:MM:SS or YYYY-MM-DD HH:MM, e.g. 2024-03-15 18:30.', 'colorbox-wp-mcp' ),
				is_scalar( $value ) ? (string) $value : gettype( $value )
			)
		);
	}
	if ( 16 === strlen( $raw ) ) {
		$raw .= ':00';
	}

	$zone = wp_timezone();
	$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $raw, $zone );
	// Porównanie z wejściem odrzuca 31 lutego i godzinę wypadającą przy zmianie czasu (PHP by je przesunął).
	if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $raw || (int) $date->format( 'Y' ) < 1970 ) {
		return new WP_Error(
			'colorbox_bad_date',
			sprintf(
				/* translators: 1: the date received, 2: site time zone name */
				__( 'The date "%1$s" does not exist in the site time zone (%2$s) or is earlier than 1970.', 'colorbox-wp-mcp' ),
				$raw,
				$zone->getName()
			)
		);
	}
	if ( $date->getTimestamp() > time() ) {
		return new WP_Error(
			'colorbox_future_date',
			sprintf(
				/* translators: 1: the date received, 2: current site time, 3: site time zone name */
				__( 'The date "%1$s" is in the future (site time now: %2$s, %3$s). The agent does not schedule or publish posts; give a past date or leave the date out.', 'colorbox-wp-mcp' ),
				$raw,
				current_time( 'mysql' ),
				$zone->getName()
			)
		);
	}

	return array( $raw, get_gmt_from_date( $raw ) );
}

/**
 * Wspólny zapis pól wpisu (nowego albo poprawianego). Status zawsze 'draft' przy tworzeniu
 * i nietknięty przy poprawce.
 *
 * @param array<string, mixed> $fields title, content, excerpt, categories, tags, date…; klucze z podkreśleniem
 *                                     są wewnętrzne (_type, _default_categories, _reason — powód do dziennika).
 * @return int|WP_Error ID wpisu.
 */
function save_post_fields( ?WP_Post $post, array $fields, string $lang ) {
	$data = array();
	$date = null;
	if ( array_key_exists( 'date', $fields ) ) {
		// Zaplanowany wpis z datą wsteczną WordPress opublikowałby od razu — termin ustawia właściciel strony.
		if ( null !== $post && 'future' === $post->post_status ) {
			/* translators: %d: post ID */
			return new WP_Error( 'colorbox_scheduled_date', sprintf( __( 'Post %d is scheduled for publication; changing its date would publish it or move the schedule. The site owner changes it in the admin panel.', 'colorbox-wp-mcp' ), $post->ID ) );
		}
		$date = parse_post_date( $fields['date'] );
		if ( is_wp_error( $date ) ) {
			return $date;
		}
		// Bez edit_date i jawnego post_date_gmt wp_update_post wyczyściłby datę szkicu (dostałby „teraz”
		// przy publikacji). Obie daty razem, żeby czas lokalny i GMT nigdy się nie rozjechały.
		$data['post_date']     = $date[0];
		$data['post_date_gmt'] = $date[1];
		$data['edit_date']     = true;
	}
	if ( array_key_exists( 'title', $fields ) ) {
		$data['post_title'] = (string) $fields['title'];
	}
	if ( array_key_exists( 'content', $fields ) ) {
		$data['post_content'] = (string) $fields['content'];
	}
	if ( array_key_exists( 'excerpt', $fields ) ) {
		$data['post_excerpt'] = (string) $fields['excerpt'];
	}

	$type = null === $post ? (string) ( $fields['_type'] ?? 'post' ) : $post->post_type;
	if ( 'page' === $type && ( array_key_exists( 'categories', $fields ) || array_key_exists( 'tags', $fields ) ) ) {
		return new WP_Error( 'colorbox_page_terms', __( 'Pages have no categories or tags.', 'colorbox-wp-mcp' ) );
	}
	if ( 'post' === $type && ! empty( $fields['parent'] ) ) {
		return new WP_Error( 'colorbox_post_parent', __( 'A parent page (parent) applies to pages only.', 'colorbox-wp-mcp' ) );
	}
	if ( array_key_exists( 'slug', $fields ) ) {
		$slug = sanitize_title( (string) $fields['slug'] );
		if ( '' === $slug ) {
			return new WP_Error( 'colorbox_bad_slug', __( 'Empty or invalid slug.', 'colorbox-wp-mcp' ) );
		}
		$data['post_name'] = $slug;
	}
	if ( array_key_exists( 'parent', $fields ) ) {
		$parent_id = (int) $fields['parent'];
		if ( $parent_id ) {
			$parent = get_post( $parent_id );
			if ( ! $parent || 'page' !== $parent->post_type ) {
				/* translators: %d: parent page ID */
				return new WP_Error( 'colorbox_bad_parent', sprintf( __( 'Parent page %d does not exist.', 'colorbox-wp-mcp' ), $parent_id ) );
			}
			$parent_lang = post_language( $parent_id );
			if ( '' !== $parent_lang && $parent_lang !== $lang ) {
				$mapped = function_exists( 'pll_get_post' ) ? (int) pll_get_post( $parent_id, $lang ) : 0;
				if ( ! $mapped ) {
					/* translators: 1: parent page ID, 2: language code */
					return new WP_Error( 'colorbox_parent_language', sprintf( __( 'Parent page %1$d has no version in language "%2$s".', 'colorbox-wp-mcp' ), $parent_id, $lang ) );
				}
				$parent_id = $mapped;
			}
		}
		$data['post_parent'] = $parent_id;
	}
	if ( array_key_exists( 'seo_description', $fields ) && mb_strlen( (string) $fields['seo_description'] ) > 300 ) {
		return new WP_Error( 'colorbox_seo_too_long', __( 'The SEO description may be up to 300 characters (ideally 120–160).', 'colorbox-wp-mcp' ) );
	}
	if ( ! empty( $fields['social_image'] ) ) {
		$ok = \Colorbox\WpMcp\Media\validate_featured_image( (int) $fields['social_image'] );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
	}

	$cats = null;
	if ( 'page' === $type ) {
		$cats = null;
	} elseif ( null === $post && ! array_key_exists( 'categories', $fields ) ) {
		// Bez tego WordPress przypisałby kategorię domyślną w języku domyślnym, także wpisom EN.
		// Kategorie bez wersji w danym języku pomijamy; gdy nic nie zostanie — domyślna w tym języku.
		$cats = array();
		foreach ( (array) ( $fields['_default_categories'] ?? array() ) as $cat_id ) {
			$mapped = function_exists( 'pll_get_term' ) ? (int) pll_get_term( (int) $cat_id, $lang ) : (int) $cat_id;
			if ( $mapped ) {
				$cats[] = $mapped;
			}
		}
		if ( ! $cats ) {
			$default = (int) get_option( 'default_category' );
			$mapped  = function_exists( 'pll_get_term' ) ? (int) pll_get_term( $default, $lang ) : 0;
			$cats    = array( $mapped ? $mapped : $default );
		}
	} elseif ( array_key_exists( 'categories', $fields ) ) {
		$cats = resolve_categories( (array) $fields['categories'], $lang );
		if ( is_wp_error( $cats ) ) {
			return $cats;
		}
	}

	$tags = null;
	if ( array_key_exists( 'tags', $fields ) ) {
		$tags = resolve_tags( (array) $fields['tags'], $lang );
		if ( is_wp_error( $tags ) ) {
			return $tags;
		}
	}

	if ( array_key_exists( 'featured_image', $fields ) ) {
		$ok = \Colorbox\WpMcp\Media\validate_featured_image( (int) $fields['featured_image'] );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
	}

	if ( null === $post ) {
		$data['post_type']   = $type;
		$data['post_status'] = 'draft';
		$data['post_author'] = get_current_user_id();
		$id                  = wp_insert_post( wp_slash( $data ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		pll_set_post_language( $id, $lang );
	} else {
		$id = $post->ID;
		if ( $data ) {
			$data['ID'] = $id;
			$result     = wp_update_post( wp_slash( $data ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
	}

	if ( null !== $date ) {
		$was_fixed = null !== $post && has_fixed_date( $post );
		if ( ! $was_fixed || $post->post_date !== $date[0] ) {
			\Colorbox\WpMcp\Manage\log_action(
				(int) $id,
				'set-date',
				(string) ( $fields['_reason'] ?? '' ),
				array(
					// Pusty date_from = wpis nie miał przypiętej daty (nowy albo szkic z datą „przy publikacji”).
					'date_from' => $was_fixed ? $post->post_date : '',
					'date_to'   => $date[0],
				)
			);
		}
	}

	if ( null !== $cats ) {
		wp_set_post_categories( $id, $cats );
	}
	if ( null !== $tags ) {
		wp_set_post_terms( $id, $tags, 'post_tag' );
	}
	if ( array_key_exists( 'seo_description', $fields ) ) {
		update_post_meta( $id, META_SEO_DESCRIPTION, sanitize_text_field( (string) $fields['seo_description'] ) );
	}
	if ( array_key_exists( 'social_image', $fields ) ) {
		if ( (int) $fields['social_image'] ) {
			update_post_meta( $id, META_SOCIAL_IMAGE, (int) $fields['social_image'] );
		} else {
			delete_post_meta( $id, META_SOCIAL_IMAGE );
		}
	}
	if ( array_key_exists( 'featured_image', $fields ) ) {
		if ( (int) $fields['featured_image'] ) {
			set_post_thumbnail( $id, (int) $fields['featured_image'] );
		} else {
			delete_post_thumbnail( $id );
		}
	}

	return (int) $id;
}

/**
 * @return true|WP_Error
 */
function assert_editable( WP_Post $post ) {
	if ( ! in_array( $post->post_status, EDITABLE_STATUSES, true ) ) {
		return new WP_Error(
			'colorbox_not_editable',
			sprintf(
				/* translators: 1: post ID, 2: post status slug */
				__( 'Post %1$d has status "%2$s". update-post edits only drafts and pending posts; for published ones use update-published-post (requires confirmation from the site owner).', 'colorbox-wp-mcp' ),
				$post->ID,
				$post->post_status
			)
		);
	}
	return true;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function create_draft( array $input ) {
	$lang = (string) ( $input['lang'] ?? default_language() );
	$ok   = validate_language( $lang );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}

	$fields          = pick_fields( $input );
	$fields['_type'] = (string) ( $input['type'] ?? 'post' );
	if ( ! in_array( $fields['_type'], POST_TYPES, true ) ) {
		return new WP_Error( 'colorbox_bad_type', __( 'Type: post or page.', 'colorbox-wp-mcp' ) );
	}
	$id = save_post_fields( null, $fields, $lang );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	return full_post( get_post( $id ) );
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function update_post( array $input ) {
	$post = get_post_or_error( (int) $input['id'] );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$ok = assert_editable( $post );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}

	$fields = pick_fields( $input );
	if ( ! $fields ) {
		return new WP_Error( 'colorbox_nothing_to_do', __( 'Provide at least one field to change: title, content, excerpt, categories, tags, featured_image, slug, seo_description, social_image, parent, date.', 'colorbox-wp-mcp' ) );
	}

	$lang = post_language( $post->ID ) ?: default_language();
	$id   = save_post_fields( $post, $fields, $lang );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	return full_post( get_post( $id ) );
}

/**
 * Tworzy albo poprawia tłumaczenie wpisu i łączy je z oryginałem w Polylangu.
 *
 * - Jeśli w języku docelowym już jest połączone tłumaczenie — poprawia je (tylko szkic/oczekujący).
 * - Jeśli podano target_id — łączy ten istniejący wpis (musi być w języku docelowym albo bez języka).
 * - W przeciwnym razie tworzy nowy szkic w języku docelowym.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function save_translation( array $input ) {
	$source = get_post_or_error( (int) $input['source_id'] );
	if ( is_wp_error( $source ) ) {
		return $source;
	}

	$lang = (string) $input['lang'];
	$ok   = validate_language( $lang );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}

	$source_lang = post_language( $source->ID );
	if ( '' === $source_lang ) {
		/* translators: %d: post ID */
		return new WP_Error( 'colorbox_source_no_language', sprintf( __( 'Post %d has no language set.', 'colorbox-wp-mcp' ), $source->ID ) );
	}
	if ( $source_lang === $lang ) {
		return new WP_Error( 'colorbox_same_language', __( 'The translation language is the same as the language of the original.', 'colorbox-wp-mcp' ) );
	}

	$translations = post_translations( $source->ID );
	$target       = null;

	if ( ! empty( $input['target_id'] ) ) {
		$target = get_post_or_error( (int) $input['target_id'] );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		if ( $target->post_type !== $source->post_type ) {
			return new WP_Error( 'colorbox_type_mismatch', __( 'The translation must be of the same type as the original (post or page).', 'colorbox-wp-mcp' ) );
		}
		if ( ! current_user_can( 'edit_post', $target->ID ) ) {
			return new WP_Error( 'colorbox_forbidden', __( 'You do not have permission to edit the target post.', 'colorbox-wp-mcp' ) );
		}
		$target_lang = post_language( $target->ID );
		if ( '' !== $target_lang && $target_lang !== $lang ) {
			/* translators: 1: post ID, 2: language code of the post, 3: requested language code */
			return new WP_Error( 'colorbox_target_language', sprintf( __( 'Post %1$d is in language "%2$s", not "%3$s".', 'colorbox-wp-mcp' ), $target->ID, $target_lang, $lang ) );
		}
		if ( isset( $translations[ $lang ] ) && $translations[ $lang ] !== $target->ID ) {
			return new WP_Error(
				'colorbox_already_linked',
				sprintf(
					/* translators: 1: language code, 2: ID of the existing translation */
					__( 'The original already has a "%1$s" translation (post %2$d). Unlink it in the admin panel first.', 'colorbox-wp-mcp' ),
					$lang,
					$translations[ $lang ]
				)
			);
		}
		if ( '' === $target_lang ) {
			pll_set_post_language( $target->ID, $lang );
		}
	} elseif ( isset( $translations[ $lang ] ) ) {
		$target = get_post( $translations[ $lang ] );
	}

	$fields = pick_fields( $input );

	if ( $target instanceof WP_Post ) {
		if ( $fields ) {
			$ok = assert_editable( $target );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$id = save_post_fields( $target, $fields, $lang );
		} else {
			$id = $target->ID;
		}
		$created = false;
	} else {
		if ( empty( $fields['title'] ) ) {
			return new WP_Error( 'colorbox_title_required', __( 'A new translation requires a title (title).', 'colorbox-wp-mcp' ) );
		}
		$fields['_type'] = $source->post_type;
		if ( 'post' === $source->post_type ) {
			// Bez podanych kategorii tłumaczenie dostaje kategorie oryginału (zamienione na wersje w swoim języku).
			$fields['_default_categories'] = wp_get_post_categories( $source->ID );
		} elseif ( $source->post_parent && ! array_key_exists( 'parent', $fields ) ) {
			// Strona-tłumaczenie dostaje rodzica w swoim języku, jeśli ten istnieje.
			$mapped = function_exists( 'pll_get_post' ) ? (int) pll_get_post( $source->post_parent, $lang ) : 0;
			if ( $mapped ) {
				$fields['parent'] = $mapped;
			}
		}
		$id                            = save_post_fields( null, $fields, $lang );
		$created = true;
	}

	if ( is_wp_error( $id ) ) {
		return $id;
	}

	$translations          = post_translations( $source->ID );
	$translations[ $lang ] = (int) $id;
	$translations[ $source_lang ] = $source->ID;
	pll_save_post_translations( $translations );

	return array(
		'created'      => $created,
		'source_id'    => $source->ID,
		'translation'  => full_post( get_post( $id ) ),
		'translations' => (object) post_translations( $source->ID ),
	);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function pick_fields( array $input ): array {
	return array_intersect_key( $input, array_flip( array( 'title', 'content', 'excerpt', 'categories', 'tags', 'featured_image', 'slug', 'seo_description', 'social_image', 'parent', 'date' ) ) );
}

/**
 * Oddanie szkicu do przeglądu (draft → pending) albo cofnięcie (pending → draft). Nic poza tymi dwoma
 * statusami: publikuje człowiek. Notatka dla właściciela strony trafia do meta i do widżetu w kokpicie.
 *
 * @param array<string, mixed> $input id, status (pending|draft), note
 * @return array<string, mixed>|WP_Error
 */
function set_review_status( array $input ) {
	$post = get_post_or_error( (int) $input['id'] );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$status = (string) $input['status'];
	if ( ! in_array( $status, array( 'pending', 'draft' ), true ) ) {
		return new WP_Error( 'colorbox_bad_status', __( 'Status: pending (submit for review) or draft (back to draft).', 'colorbox-wp-mcp' ) );
	}
	if ( ! in_array( $post->post_status, array( 'draft', 'pending' ), true ) ) {
		/* translators: 1: post ID, 2: post status slug */
		return new WP_Error( 'colorbox_not_editable', sprintf( __( 'Post %1$d has status "%2$s"; only drafts and pending posts can be changed.', 'colorbox-wp-mcp' ), $post->ID, $post->post_status ) );
	}

	$result = wp_update_post(
		array(
			'ID'          => $post->ID,
			'post_status' => $status,
		),
		true
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( 'pending' === $status ) {
		update_post_meta( $post->ID, META_REVIEW_NOTE, sanitize_textarea_field( (string) ( $input['note'] ?? '' ) ) );
	} else {
		delete_post_meta( $post->ID, META_REVIEW_NOTE );
	}

	return full_post( get_post( $post->ID ) );
}

/**
 * Nowa kategoria w jednym języku, opcjonalnie od razu z tłumaczeniami połączonymi w Polylangu.
 * Kategorii agent nie usuwa ani nie zmienia — tylko dodaje.
 *
 * @param array<string, mixed> $input name, lang, slug?, description?, parent?, translations?: [{lang,name,slug?,description?}]
 * @return array<string, mixed>|WP_Error
 */
function create_category( array $input ) {
	$items = array_merge(
		array(
			array(
				'lang'        => (string) ( $input['lang'] ?? default_language() ),
				'name'        => (string) $input['name'],
				'slug'        => (string) ( $input['slug'] ?? '' ),
				'description' => (string) ( $input['description'] ?? '' ),
			),
		),
		array_map( static fn( $t ) => (array) $t, (array) ( $input['translations'] ?? array() ) )
	);

	// Najpierw sprawdzenie wszystkiego, dopiero potem zapis — żeby nie zostawić połowy.
	$seen = array();
	foreach ( $items as $item ) {
		$lang = (string) ( $item['lang'] ?? '' );
		$ok   = validate_language( $lang );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( isset( $seen[ $lang ] ) ) {
			/* translators: %s: language code */
			return new WP_Error( 'colorbox_duplicate_language', sprintf( __( 'Language "%s" was given twice.', 'colorbox-wp-mcp' ), $lang ) );
		}
		$seen[ $lang ] = true;
		$name          = trim( sanitize_text_field( (string) ( $item['name'] ?? '' ) ) );
		if ( '' === $name ) {
			return new WP_Error( 'colorbox_name_required', __( 'Every category version needs a name.', 'colorbox-wp-mcp' ) );
		}
		$existing = get_terms(
			array(
				'taxonomy'   => 'category',
				'name'       => $name,
				'hide_empty' => false,
				'lang'       => $lang,
			)
		);
		if ( is_array( $existing ) && $existing ) {
			/* translators: 1: category name, 2: language code, 3: category ID */
			return new WP_Error( 'colorbox_category_exists', sprintf( __( 'Category "%1$s" (%2$s) already exists, ID %3$d.', 'colorbox-wp-mcp' ), $name, $lang, $existing[0]->term_id ) );
		}
	}

	$parent = (int) ( $input['parent'] ?? 0 );
	if ( $parent && ! get_term( $parent, 'category' ) instanceof \WP_Term ) {
		/* translators: %d: parent category ID */
		return new WP_Error( 'colorbox_bad_parent', sprintf( __( 'There is no parent category %d.', 'colorbox-wp-mcp' ), $parent ) );
	}

	$created = array();
	foreach ( $items as $item ) {
		$lang = (string) $item['lang'];
		$name = trim( sanitize_text_field( (string) $item['name'] ) );
		$slug = sanitize_title( '' !== (string) ( $item['slug'] ?? '' ) ? (string) $item['slug'] : $name );
		if ( term_exists( $slug, 'category' ) ) {
			$slug .= '-' . $lang;
		}
		$parent_in_lang = $parent && function_exists( 'pll_get_term' ) ? (int) pll_get_term( $parent, $lang ) : 0;

		$term = wp_insert_term(
			$name,
			'category',
			array(
				'slug'        => $slug,
				'description' => sanitize_textarea_field( (string) ( $item['description'] ?? '' ) ),
				'parent'      => $parent_in_lang,
			)
		);
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		pll_set_term_language( (int) $term['term_id'], $lang );
		$created[ $lang ] = (int) $term['term_id'];
	}

	if ( count( $created ) > 1 && function_exists( 'pll_save_term_translations' ) ) {
		pll_save_term_translations( $created );
	}

	return array(
		'created'    => (object) $created,
		'categories' => list_categories( '' ),
	);
}
