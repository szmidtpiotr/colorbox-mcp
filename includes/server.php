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
		/* translators: %s: site host name */
		sprintf( __( 'Content of the %s site: drafts, translations, images, comments. The agent does not publish.', 'colorbox-wp-mcp' ), wp_parse_url( home_url(), PHP_URL_HOST ) ),
		\Colorbox\WpMcp\VERSION,
		array( \WP\MCP\Transport\HttpTransport::class ),
		\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
		null,
		Settings\enabled_tools(),
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
