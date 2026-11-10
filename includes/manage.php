<?php
/**
 * Edycja i usuwanie poza szkicami: poprawki opublikowanych wpisów (z rewizją do cofnięcia), kosz dla
 * wpisów i stron, zmiana i usuwanie kategorii, opis i usuwanie obrazków.
 *
 * Te operacje są dozwolone, bo klient MCP pyta o zgodę przy każdym
 * wywołaniu („Zawsze pytaj” w Perplexity). Dlatego są osobnymi narzędziami z adnotacją destructive —
 * zgodę ustawia się per narzędzie, a zwykłe update-post na szkicach dalej idzie bez pytania.
 * Publikowania agent nadal nie ma.
 */

namespace Colorbox\WpMcp\Manage;

use Colorbox\WpMcp\Content;
use Colorbox\WpMcp\Media;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

const PUBLISHED_STATUSES = array( 'publish', 'future', 'private' );
const META_LOG           = '_colorbox_agent_log';
const LOG_LIMIT          = 20;

function reason( array $in ) {
	$reason = trim( sanitize_text_field( (string) ( $in['reason'] ?? '' ) ) );
	return '' === $reason ? new WP_Error( 'colorbox_reason_required', __( 'Provide reason: a short explanation of the change.', 'colorbox-wp-mcp' ) ) : $reason;
}

/** Dziennik zmian agenta przy wpisie (ostatnie 20), widoczny w get-post. */
function log_action( int $id, string $action, string $reason, array $extra = array() ): void {
	$log   = (array) get_post_meta( $id, META_LOG, true );
	$log[] = array(
		'action' => $action,
		'reason' => $reason,
		'by'     => wp_get_current_user()->user_login,
		'at'     => current_time( 'mysql' ),
	) + $extra;
	update_post_meta( $id, META_LOG, array_slice( $log, -LOG_LIMIT ) );
}

/**
 * Najnowsza rewizja odpowiadająca obecnej treści — zapisywana przed zmianą, żeby było do czego wrócić.
 */
function snapshot_revision( int $id ): int {
	$saved = wp_save_post_revision( $id );
	if ( $saved && ! is_wp_error( $saved ) ) {
		return (int) $saved;
	}
	$latest = wp_get_post_revisions( $id, array( 'numberposts' => 1 ) );
	return $latest ? (int) array_key_first( $latest ) : 0;
}

/**
 * @return array<string, mixed>|WP_Error
 */
function update_published_post( array $in ) {
	$post = Content\get_post_or_error( (int) $in['id'] );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	if ( ! in_array( $post->post_status, PUBLISHED_STATUSES, true ) ) {
		/* translators: 1: post ID, 2: post status slug */
		return new WP_Error( 'colorbox_not_published', sprintf( __( 'Post %1$d has status "%2$s"; drafts and pending posts are edited with update-post.', 'colorbox-wp-mcp' ), $post->ID, $post->post_status ) );
	}
	$reason = reason( $in );
	if ( is_wp_error( $reason ) ) {
		return $reason;
	}
	$fields = Content\pick_fields( $in );
	if ( ! $fields ) {
		return new WP_Error( 'colorbox_nothing_to_do', __( 'Provide at least one field to change.', 'colorbox-wp-mcp' ) );
	}

	$before = snapshot_revision( $post->ID );
	$lang   = Content\post_language( $post->ID ) ?: Content\default_language();
	$id     = Content\save_post_fields( $post, $fields, $lang );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	log_action( $post->ID, 'update-published', $reason, array( 'undo_revision_id' => $before ) );

	return Content\full_post( get_post( $id ) ) + array(
		'undo_revision_id' => $before,
		'how_to_undo'      => $before
			? sprintf(
				/* translators: 1: post ID, 2: revision ID */
				__( 'restore-post-revision with id=%1$d and revision_id=%2$d restores the title, content and excerpt from before the change (categories, tags, SEO description and images are not stored in the revision).', 'colorbox-wp-mcp' ),
				$post->ID,
				$before
			)
			: __( 'No revision available; the change can only be undone manually.', 'colorbox-wp-mcp' ),
	);
}

/**
 * @return array<int, array<string, mixed>>
 */
function list_revisions( WP_Post $post ): array {
	$out = array();
	foreach ( wp_get_post_revisions( $post->ID, array( 'numberposts' => 10 ) ) as $rev ) {
		$out[] = array(
			'revision_id' => $rev->ID,
			'date'        => $rev->post_modified,
			'author'      => get_the_author_meta( 'user_login', (int) $rev->post_author ),
			'title'       => $rev->post_title,
		);
	}
	return $out;
}

/**
 * Bez revision_id: lista ostatnich rewizji. Z revision_id: przywrócenie (przed nim zapis obecnej wersji,
 * więc i to da się cofnąć).
 *
 * @return array<string, mixed>|WP_Error
 */
function restore_post_revision( array $in ) {
	$post = Content\get_post_or_error( (int) $in['id'] );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$rev_id = (int) ( $in['revision_id'] ?? 0 );
	if ( ! $rev_id ) {
		return array(
			'id'        => $post->ID,
			'title'     => $post->post_title,
			'status'    => $post->post_status,
			'revisions' => list_revisions( $post ),
		);
	}
	$rev = wp_get_post_revision( $rev_id );
	if ( ! $rev || (int) $rev->post_parent !== $post->ID ) {
		/* translators: 1: revision ID, 2: post ID */
		return new WP_Error( 'colorbox_bad_revision', sprintf( __( 'Revision %1$d does not belong to post %2$d.', 'colorbox-wp-mcp' ), $rev_id, $post->ID ) );
	}
	$reason = reason( $in );
	if ( is_wp_error( $reason ) ) {
		return $reason;
	}
	$before = snapshot_revision( $post->ID );
	if ( ! wp_restore_post_revision( $rev_id ) ) {
		return new WP_Error( 'colorbox_restore_failed', __( 'WordPress did not restore the revision.', 'colorbox-wp-mcp' ) );
	}
	log_action( $post->ID, 'restore-revision', $reason, array( 'revision_id' => $rev_id, 'undo_revision_id' => $before ) );

	return Content\full_post( get_post( $post->ID ) ) + array( 'undo_revision_id' => $before );
}

/**
 * Strona główna i strona bloga we wszystkich językach. Opcje czytane prosto z bazy, bo Polylang
 * podmienia page_on_front zależnie od bieżącego języka zapytania.
 *
 * @return int[]
 */
function protected_page_ids(): array {
	global $wpdb;
	$ids = array_map( 'intval', $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name IN ('page_on_front','page_for_posts')" ) );
	if ( function_exists( 'PLL' ) && isset( PLL()->model ) ) {
		foreach ( PLL()->model->get_languages_list() as $language ) {
			$ids[] = (int) $language->page_on_front;
			$ids[] = (int) $language->page_for_posts;
		}
	}
	foreach ( $ids as $id ) {
		if ( $id ) {
			$ids = array_merge( $ids, array_values( Content\post_translations( $id ) ) );
		}
	}
	return array_values( array_unique( array_filter( $ids ) ) );
}

/**
 * @return array<string, mixed>|WP_Error
 */
function trash_post( array $in ) {
	$post = Content\get_post_or_error( (int) $in['id'] );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	if ( 'trash' === $post->post_status ) {
		/* translators: %d: post ID */
		return new WP_Error( 'colorbox_already_trashed', sprintf( __( 'Post %d is already in the trash.', 'colorbox-wp-mcp' ), $post->ID ) );
	}
	if ( in_array( $post->ID, protected_page_ids(), true ) ) {
		/* translators: %d: page ID */
		return new WP_Error( 'colorbox_special_page', sprintf( __( 'Page %d is the front page or the posts page (or a translation of one), so it cannot be moved to the trash. The site owner can change this in Settings → Reading.', 'colorbox-wp-mcp' ), $post->ID ) );
	}
	$reason = reason( $in );
	if ( is_wp_error( $reason ) ) {
		return $reason;
	}
	if ( 'page' === $post->post_type ) {
		$children = get_children(
			array(
				'post_parent' => $post->ID,
				'post_type'   => 'page',
				'post_status' => array( 'draft', 'pending', 'publish', 'future', 'private' ),
			)
		);
		if ( $children ) {
			/* translators: 1: page ID, 2: comma-separated list of child page IDs */
			return new WP_Error( 'colorbox_has_children', sprintf( __( 'Page %1$d has child pages (%2$s); change their parent first.', 'colorbox-wp-mcp' ), $post->ID, implode( ', ', array_keys( $children ) ) ) );
		}
	}

	$was = $post->post_status;
	log_action( $post->ID, 'trash', $reason, array( 'status_before' => $was ) );
	if ( ! wp_trash_post( $post->ID ) ) {
		return new WP_Error( 'colorbox_trash_failed', __( 'WordPress did not move the post to the trash.', 'colorbox-wp-mcp' ) );
	}

	$others = array_diff_key( Content\post_translations( $post->ID ), array( Content\post_language( $post->ID ) => 0 ) );
	return array(
		'id'                 => $post->ID,
		'title'              => $post->post_title,
		'status_before'      => $was,
		'status'             => 'trash',
		'translations_left'  => (object) $others,
		'note'               => __( 'Kept in the trash for 30 days, then WordPress deletes it permanently. restore-post restores it as a draft (only the site owner can publish it again). Translations were not touched.', 'colorbox-wp-mcp' ),
	);
}

/**
 * @return array<string, mixed>|WP_Error
 */
function restore_post( array $in ) {
	$post = Content\get_post_or_error( (int) $in['id'] );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	if ( 'trash' !== $post->post_status ) {
		/* translators: 1: post ID, 2: post status slug */
		return new WP_Error( 'colorbox_not_trashed', sprintf( __( 'Post %1$d is not in the trash (status "%2$s").', 'colorbox-wp-mcp' ), $post->ID, $post->post_status ) );
	}
	if ( ! wp_untrash_post( $post->ID ) ) {
		return new WP_Error( 'colorbox_restore_failed', __( 'WordPress did not restore the post from the trash.', 'colorbox-wp-mcp' ) );
	}
	log_action( $post->ID, 'restore', sanitize_text_field( (string) ( $in['reason'] ?? '' ) ) );
	return Content\full_post( get_post( $post->ID ) );
}

/**
 * @return \WP_Term|WP_Error
 */
function get_category_or_error( int $id ) {
	$term = get_term( $id, 'category' );
	/* translators: %d: category ID */
	return $term instanceof \WP_Term ? $term : new WP_Error( 'colorbox_not_found', sprintf( __( 'There is no category with ID %d.', 'colorbox-wp-mcp' ), $id ) );
}

/** Kategoria domyślna we wszystkich językach (tych WordPress nie pozwala usunąć). */
function default_category_ids(): array {
	$default = (int) get_option( 'default_category' );
	$ids     = array( $default );
	if ( function_exists( 'pll_get_term_translations' ) ) {
		$ids = array_merge( $ids, array_map( 'intval', (array) pll_get_term_translations( $default ) ) );
	}
	return array_values( array_unique( $ids ) );
}

/**
 * @return array<string, mixed>|WP_Error
 */
function update_category( array $in ) {
	$term = get_category_or_error( (int) $in['id'] );
	if ( is_wp_error( $term ) ) {
		return $term;
	}
	$args = array();
	if ( isset( $in['name'] ) ) {
		$name = trim( sanitize_text_field( (string) $in['name'] ) );
		if ( '' === $name ) {
			return new WP_Error( 'colorbox_name_required', __( 'The category name is empty.', 'colorbox-wp-mcp' ) );
		}
		$args['name'] = $name;
	}
	if ( isset( $in['slug'] ) ) {
		$args['slug'] = sanitize_title( (string) $in['slug'] );
	}
	if ( isset( $in['description'] ) ) {
		$args['description'] = sanitize_textarea_field( (string) $in['description'] );
	}
	if ( isset( $in['parent'] ) ) {
		$parent = (int) $in['parent'];
		if ( $parent ) {
			$p = get_category_or_error( $parent );
			if ( is_wp_error( $p ) ) {
				return $p;
			}
			$lang = function_exists( 'pll_get_term_language' ) ? (string) pll_get_term_language( $term->term_id ) : '';
			if ( $lang && function_exists( 'pll_get_term' ) ) {
				$parent = (int) pll_get_term( $parent, $lang );
				if ( ! $parent ) {
					/* translators: 1: parent category ID, 2: language code */
					return new WP_Error( 'colorbox_parent_language', sprintf( __( 'Parent category %1$d has no version in language "%2$s".', 'colorbox-wp-mcp' ), (int) $in['parent'], $lang ) );
				}
			}
			if ( $parent === $term->term_id || term_is_ancestor_of( $term->term_id, $parent, 'category' ) ) {
				return new WP_Error( 'colorbox_parent_loop', __( 'A category cannot be a child of itself or of its own subcategory.', 'colorbox-wp-mcp' ) );
			}
		}
		$args['parent'] = $parent;
	}
	if ( ! $args ) {
		return new WP_Error( 'colorbox_nothing_to_do', __( 'Provide name, slug, description or parent.', 'colorbox-wp-mcp' ) );
	}
	$result = wp_update_term( $term->term_id, 'category', $args );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$fresh = get_term( $term->term_id, 'category' );
	return array(
		'id'          => $fresh->term_id,
		'name'        => $fresh->name,
		'slug'        => $fresh->slug,
		'description' => $fresh->description,
		'parent'      => $fresh->parent,
		'note'        => __( 'Only this language version was changed; update the category translations separately.', 'colorbox-wp-mcp' ),
	);
}

/**
 * Wpisy, które zostałyby bez kategorii, dostają kategorię domyślną w swoim języku (WordPress sam dałby
 * domyślną polską także wpisom EN).
 *
 * @return array<string, mixed>|WP_Error
 */
function delete_category( array $in ) {
	$term = get_category_or_error( (int) $in['id'] );
	if ( is_wp_error( $term ) ) {
		return $term;
	}
	if ( in_array( $term->term_id, default_category_ids(), true ) ) {
		/* translators: %s: category name */
		return new WP_Error( 'colorbox_default_category', sprintf( __( '"%s" is the default category; WordPress does not allow deleting it.', 'colorbox-wp-mcp' ), $term->name ) );
	}
	$reason = reason( $in );
	if ( is_wp_error( $reason ) ) {
		return $reason;
	}
	$children = get_terms(
		array(
			'taxonomy'   => 'category',
			'parent'     => $term->term_id,
			'hide_empty' => false,
			'fields'     => 'ids',
			'lang'       => '',
		)
	);

	$posts = get_posts(
		array(
			'post_type'   => 'post',
			'post_status' => 'any',
			'numberposts' => -1,
			'category'    => $term->term_id,
			'lang'        => '',
			'fields'      => 'ids',
		)
	);
	$reassigned = array();
	foreach ( $posts as $post_id ) {
		$cats = array_diff( wp_get_post_categories( $post_id ), array( $term->term_id ) );
		if ( ! $cats ) {
			$lang    = Content\post_language( $post_id );
			$default = (int) get_option( 'default_category' );
			$mapped  = $lang && function_exists( 'pll_get_term' ) ? (int) pll_get_term( $default, $lang ) : 0;
			$cats    = array( $mapped ? $mapped : $default );
			wp_set_post_categories( $post_id, $cats );
			$reassigned[] = array(
				'id'           => $post_id,
				'title'        => get_the_title( $post_id ),
				'new_category' => get_cat_name( $cats[0] ),
			);
		}
	}

	$name   = $term->name;
	$result = wp_delete_term( $term->term_id, 'category' );
	if ( is_wp_error( $result ) || ! $result ) {
		return is_wp_error( $result ) ? $result : new WP_Error( 'colorbox_delete_failed', __( 'WordPress did not delete the category.', 'colorbox-wp-mcp' ) );
	}
	return array(
		'deleted'              => $name,
		'reason'               => $reason,
		'posts_in_category'    => count( $posts ),
		'moved_to_default'     => $reassigned,
		'subcategories_raised' => array_map( 'intval', (array) $children ),
		'note'                 => __( 'Deleting a category is permanent. Its translations were kept; delete them separately if needed.', 'colorbox-wp-mcp' ),
	);
}

/**
 * Gdzie obrazek jest użyty: obrazek wyróżniający, obrazek do udostępnień, treść (blok albo adres pliku).
 *
 * @return array<int, array<string, mixed>>
 */
function image_usage( int $id ): array {
	global $wpdb;
	$found = array();

	$meta_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_thumbnail_id', %s) AND meta_value = %s",
			Content\META_SOCIAL_IMAGE,
			(string) $id
		)
	);
	foreach ( $meta_ids as $pid ) {
		$found[ (int) $pid ][] = __( 'featured image or social sharing image', 'colorbox-wp-mcp' );
	}

	$file  = (string) get_post_meta( $id, '_wp_attached_file', true );
	$stem  = '' !== $file ? preg_replace( '/\.[a-z0-9]+$/i', '', $file ) : '';
	$like  = array( '%wp-image-' . $id . '"%', '%wp-image-' . $id . ' %', '%"id":' . $id . ',%', '%"id":' . $id . '}%' );
	$where = implode( ' OR ', array_fill( 0, count( $like ), 'post_content LIKE %s' ) );
	if ( '' !== $stem ) {
		$where .= ' OR post_content LIKE %s';
		$like[] = '%' . $wpdb->esc_like( $stem ) . '%';
	}
	$rows = $wpdb->get_col(
		$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('post','page') AND ({$where})", $like ) // phpcs:ignore WordPress.DB.PreparedSQL
	);
	foreach ( $rows as $pid ) {
		$found[ (int) $pid ][] = __( 'in content', 'colorbox-wp-mcp' );
	}

	$out = array();
	foreach ( $found as $pid => $where_used ) {
		$out[] = array(
			'id'     => $pid,
			'title'  => get_the_title( $pid ),
			'status' => get_post_status( $pid ),
			'where'  => array_values( array_unique( $where_used ) ),
		);
	}
	return $out;
}

/**
 * @return int|WP_Error
 */
function image_or_error( int $id ) {
	/* translators: %d: attachment ID */
	return wp_attachment_is_image( $id ) ? $id : new WP_Error( 'colorbox_not_found', sprintf( __( 'There is no image with ID %d.', 'colorbox-wp-mcp' ), $id ) );
}

/**
 * @return array<string, mixed>|WP_Error
 */
function update_image( array $in ) {
	$id = image_or_error( (int) $in['id'] );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	if ( ! array_intersect_key( $in, array_flip( array( 'alt', 'caption', 'title' ) ) ) ) {
		return new WP_Error( 'colorbox_nothing_to_do', __( 'Provide alt, caption or title.', 'colorbox-wp-mcp' ) );
	}
	Media\update_alt_and_caption( $id, $in );
	if ( isset( $in['title'] ) ) {
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => sanitize_text_field( (string) $in['title'] ),
			)
		);
	}
	return Media\describe_attachment( $id, true ) + array(
		'used_in' => image_usage( $id ),
		'note'    => __( 'This changes the media library and future insertions. Image blocks already inserted into posts keep their own alt text and caption in the content; fix them there (update-post / update-published-post).', 'colorbox-wp-mcp' ),
	);
}

/**
 * Trwałe usunięcie obrazka razem z plikami. Odmawia, gdy obrazek jest gdziekolwiek użyty.
 *
 * @return array<string, mixed>|WP_Error
 */
function delete_image( array $in ) {
	$id = image_or_error( (int) $in['id'] );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	$reason = reason( $in );
	if ( is_wp_error( $reason ) ) {
		return $reason;
	}
	$used = image_usage( $id );
	if ( $used ) {
		return new WP_Error(
			'colorbox_image_in_use',
			sprintf(
				/* translators: 1: attachment ID, 2: semicolon-separated list of posts using the image */
				__( 'Image %1$d is in use: %2$s. Remove it from those posts first.', 'colorbox-wp-mcp' ),
				$id,
				implode(
					'; ',
					array_map(
						static fn( $u ) => sprintf(
							/* translators: 1: post ID, 2: post title, 3: post status slug, 4: where the image is used */
							__( '%1$d "%2$s" (%3$s, %4$s)', 'colorbox-wp-mcp' ),
							$u['id'],
							$u['title'],
							$u['status'],
							implode( ', ', $u['where'] )
						),
						$used
					)
				)
			)
		);
	}
	$title = get_the_title( $id );
	$file  = (string) get_post_meta( $id, '_wp_attached_file', true );
	if ( ! wp_delete_attachment( $id, true ) ) {
		return new WP_Error( 'colorbox_delete_failed', __( 'WordPress did not delete the image.', 'colorbox-wp-mcp' ) );
	}
	return array(
		'deleted' => $id,
		'title'   => $title,
		'file'    => $file,
		'reason'  => $reason,
		'note'    => __( 'Deleted permanently together with its files (the media library has no trash).', 'colorbox-wp-mcp' ),
	);
}
