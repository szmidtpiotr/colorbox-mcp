<?php
/**
 * Plugin Name:       Colorbox MCP — tools for AI agents
 * Plugin URI:        https://github.com/szmidtpiotr/colorbox-mcp
 * Description:       MCP server (official WordPress MCP Adapter + Abilities API) for an AI agent: drafts and translations (Polylang), images, code from GitHub, comments, SEO. The agent never publishes. Bearer-token login valid only on the MCP endpoint. Configure under Settings → MCP Agent.
 * Version:           0.3.1
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Piotr Szmidt / Colorbox Studio
 * License:           GPL-2.0-or-later
 * Text Domain:       colorbox-wp-mcp
 * Domain Path:       /languages
 */

namespace Colorbox\WpMcp;

defined( 'ABSPATH' ) || exit;

const VERSION         = '0.3.1';
const ROUTE_NAMESPACE = 'colorbox';
const ROUTE           = 'mcp';
const SERVER_ID       = 'colorbox-mcp';
const FILE            = __FILE__;

$autoload = __DIR__ . '/vendor/autoload.php';
if ( ! is_readable( $autoload ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . wp_kses_post( __( 'Colorbox MCP: the <code>vendor/</code> directory is missing (run <code>composer install --no-dev</code>).', 'colorbox-wp-mcp' ) ) . '</p></div>';
		}
	);
	return;
}
require_once $autoload;

require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/content.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/comments.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/review.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/code.php';
require_once __DIR__ . '/includes/manage.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/abilities.php';
require_once __DIR__ . '/includes/server.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/cli.php';
}

// Ładujemy adapter tak jak jego własny plik wtyczki (definiuje WP_MCP_VERSION, więc bez ostrzeżenia
// „bundled dependency”). Domyślny serwer adaptera wyłączamy w server.php — wystawiamy tylko własny.
if ( class_exists( \WP\MCP\Plugin::class ) && ! defined( 'WP_MCP_VERSION' ) ) {
	\WP\MCP\Plugin::instance();
}

I18n\boot();
Auth\boot();
Abilities\boot();
Comments\boot();
Seo\boot();
Review\boot();
Server\boot();
Settings\boot();
