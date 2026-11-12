<?php
/**
 * Serwer MCP pod /wp-json/colorbox/mcp z jawną listą narzędzi. Domyślny serwer adaptera
 * (/wp-json/mcp/mcp-adapter-default-server, z meta-narzędziem „execute-ability”) jest wyłączony,
 * żeby agent widział tylko narzędzia tej wtyczki.
 */

namespace Colorbox\WpMcp\Server;

use Colorbox\WpMcp\Abilities;
use Colorbox\WpMcp\Settings;

defined( 'ABSPATH' ) || exit;

function boot(): void {
	add_filter( 'mcp_adapter_create_default_server', '__return_false' );
	add_action( 'mcp_adapter_init', __NAMESPACE__ . '\\register' );
}

/**
 * @param \WP\MCP\Core\McpAdapter $adapter
 */
function register( $adapter ): void {
	$result = $adapter->create_server(
		\Colorbox\WpMcp\SERVER_ID,
		\Colorbox\WpMcp\ROUTE_NAMESPACE,
		\Colorbox\WpMcp\ROUTE,
		/* translators: %s: site name */
		sprintf( __( '%s — content', 'colorbox-wp-mcp' ), get_bloginfo( 'name' ) ),
		instructions(),
		\Colorbox\WpMcp\VERSION,
		array( \WP\MCP\Transport\HttpTransport::class ),
		\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
		null,
		/**
		 * Lista narzędzi serwera. Inna wtyczka może dopisać tu własne abilities (zarejestrowane na
		 * wp_abilities_api_init), np. narzędzie potrzebne tylko na jednej stronie.
		 *
		 * @param string[] $tools Nazwy abilities włączonych w panelu.
		 */
		(array) apply_filters( 'colorbox_mcp_tools', Settings\enabled_tools() ),
		array(),
		array(),
		__NAMESPACE__ . '\\transport_permission'
	);

	if ( is_wp_error( $result ) ) {
		error_log( 'colorbox-wp-mcp: ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

/**
 * Wejście na endpoint: zalogowany użytkownik, który może edytować wpisy.
 * Brak logowania → WordPress zwraca 401, brak uprawnień → 403.
 */
function transport_permission( $request ): bool {
	return current_user_can( 'edit_posts' );
}

/**
 * Instrukcje serwera (pole „instructions” w odpowiedzi initialize): klient pokazuje je modelowi przed narzędziami.
 * Inne wtyczki mogą je uzupełnić filtrem colorbox_mcp_instructions, np. o bloki swojego motywu.
 */
function instructions(): string {
	$text = sprintf(
		/* translators: %s: site host name */
		__( 'Tools for the content of the %s site: drafts, pages, translations, images, code, comments. You never publish: when a draft is ready, hand it over with colorbox-set-review-status and the owner reviews and publishes it. Post content is WordPress block markup. Tools that prepare content (colorbox-import-image, colorbox-github-code, colorbox-github-document) return ready blocks: put them into the content with colorbox-update-post. Languages come from Polylang (colorbox-list-languages); a translation of a post is saved with colorbox-save-translation.', 'colorbox-wp-mcp' ),
		wp_parse_url( home_url(), PHP_URL_HOST )
	);
	/**
	 * @param string $text Instrukcje serwera MCP.
	 */
	return (string) apply_filters( 'colorbox_mcp_instructions', $text );
}
