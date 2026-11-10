<?php
/**
 * Panel: widżet w kokpicie „Do przeglądu od agenta” i licznik na pasku admina. Zastępuje powiadomienie
 * e-mailem (wiele serwerów WordPressa nie ma skonfigurowanej poczty).
 */

namespace Colorbox\WpMcp\Review;

use Colorbox\WpMcp\Content;

defined( 'ABSPATH' ) || exit;

function boot(): void {
	add_action( 'wp_dashboard_setup', __NAMESPACE__ . '\\register_widget' );
	add_action( 'admin_bar_menu', __NAMESPACE__ . '\\admin_bar', 90 );
}

/**
 * @return \WP_Post[]
 */
function pending_items(): array {
	return get_posts(
		array(
			'post_type'   => Content\POST_TYPES,
			'post_status' => 'pending',
			'numberposts' => 20,
			'orderby'     => 'modified',
			'order'       => 'DESC',
			'lang'        => '',
		)
	);
}

function register_widget(): void {
	if ( ! current_user_can( 'publish_posts' ) ) {
		return;
	}
	wp_add_dashboard_widget( 'colorbox_review', __( 'Waiting for review (from agent)', 'colorbox-wp-mcp' ), __NAMESPACE__ . '\\render_widget' );

	// Na górę kokpitu.
	global $wp_meta_boxes;
	$normal = $wp_meta_boxes['dashboard']['normal']['core'] ?? array();
	if ( isset( $normal['colorbox_review'] ) ) {
		$wp_meta_boxes['dashboard']['normal']['core'] = array( 'colorbox_review' => $normal['colorbox_review'] ) + $normal; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	}
}

function render_widget(): void {
	$items = pending_items();
	if ( ! $items ) {
		echo '<p>' . esc_html__( 'Nothing is waiting for review.', 'colorbox-wp-mcp' ) . '</p>';
		return;
	}
	echo '<ul>';
	foreach ( $items as $post ) {
		$note = (string) get_post_meta( $post->ID, Content\META_REVIEW_NOTE, true );
		$lang = Content\post_language( $post->ID );
		printf(
			'<li><strong><a href="%1$s">%2$s</a></strong> <span style="color:#646970">(%3$s%4$s, %5$s)</span>%6$s</li>',
			esc_url( admin_url( 'post.php?post=' . $post->ID . '&action=edit' ) ),
			esc_html( get_the_title( $post ) ?: __( '(no title)', 'colorbox-wp-mcp' ) ),
			'page' === $post->post_type ? esc_html__( 'page', 'colorbox-wp-mcp' ) : esc_html__( 'post', 'colorbox-wp-mcp' ),
			$lang ? ' ' . esc_html( strtoupper( $lang ) ) : '',
			esc_html( mysql2date( 'j.m H:i', $post->post_modified ) ),
			'' !== $note ? '<br><em>' . esc_html( $note ) . '</em>' : ''
		);
	}
	echo '</ul>';
}

function admin_bar( \WP_Admin_Bar $bar ): void {
	if ( ! is_admin_bar_showing() || ! current_user_can( 'publish_posts' ) ) {
		return;
	}
	$count = count( pending_items() );
	if ( ! $count ) {
		return;
	}
	$bar->add_node(
		array(
			'id'    => 'colorbox-review',
			/* translators: %d: number of items waiting for review */
			'title' => sprintf( __( 'To review: %d', 'colorbox-wp-mcp' ), $count ),
			'href'  => admin_url( 'index.php#colorbox_review' ),
		)
	);
}
