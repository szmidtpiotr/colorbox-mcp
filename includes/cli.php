<?php
/**
 * WP-CLI commands: token management, GitHub token and a temporary header log.
 *
 *   wp colorbox-mcp token create --for=agent --label=claude-code
 *   wp colorbox-mcp token list
 *   wp colorbox-mcp token revoke <id>
 *   wp colorbox-mcp headers on [--minutes=30] | off | show | clear
 *   wp colorbox-mcp github-token set < file | status | delete
 */

namespace Colorbox\WpMcp\Cli;

use Colorbox\WpMcp\Auth;
use Colorbox\WpMcp\Media;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

class Token_Command {

	/**
	 * Creates a token and prints it ONCE.
	 *
	 * ## OPTIONS
	 *
	 * --for=<login>
	 * : The user account the token acts as (not an administrator).
	 *
	 * --label=<label>
	 * : What the token is for, e.g. claude-code or perplexity.
	 *
	 * [--porcelain]
	 * : Print only the token.
	 */
	public function create( $args, $assoc ) {
		$user = get_user_by( 'login', $assoc['for'] );
		if ( ! $user ) {
			WP_CLI::error( __( 'No such user.', 'colorbox-wp-mcp' ) );
		}
		if ( user_can( $user, 'manage_options' ) ) {
			WP_CLI::error( __( 'Refused: no tokens for administrators. Use an account with the Editor role.', 'colorbox-wp-mcp' ) );
		}
		$t = Auth\create_token( (int) $user->ID, sanitize_text_field( $assoc['label'] ) );
		if ( ! empty( $assoc['porcelain'] ) ) {
			WP_CLI::line( $t['token'] );
			return;
		}
		/* translators: 1: token ID, 2: user login */
		WP_CLI::success( sprintf( __( 'Token %1$s for %2$s created. Save it now, it cannot be shown again:', 'colorbox-wp-mcp' ), $t['id'], $user->user_login ) );
		WP_CLI::line( $t['token'] );
	}

	/**
	 * Lists tokens (without their values).
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc ) {
		$rows = array();
		foreach ( Auth\get_tokens() as $id => $d ) {
			$u      = get_userdata( (int) $d['user_id'] );
			$rows[] = array(
				'id'        => $id,
				'user'      => $u ? $u->user_login : __( '(none)', 'colorbox-wp-mcp' ),
				'label'     => $d['label'],
				'created'   => gmdate( 'Y-m-d H:i', (int) $d['created'] ),
				'last_used' => $d['last_used'] ? gmdate( 'Y-m-d H:i', (int) $d['last_used'] ) : '-',
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'user', 'label', 'created', 'last_used' ) );
	}

	/**
	 * Revokes a token.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : ID from `token list`.
	 */
	public function revoke( $args ) {
		if ( Auth\revoke_token( $args[0] ) ) {
			WP_CLI::success( __( 'Token revoked.', 'colorbox-wp-mcp' ) );
		} else {
			WP_CLI::error( __( 'No such token.', 'colorbox-wp-mcp' ) );
		}
	}
}

/**
 * Temporary log of headers arriving at the MCP endpoint (names and lengths only, never values).
 *
 * ## OPTIONS
 *
 * <action>
 * : on | off | show | clear
 *
 * [--minutes=<n>]
 * : How many minutes to keep it on (default 30, max 240).
 */
function headers_command( $args, $assoc ) {
	switch ( $args[0] ?? 'show' ) {
		case 'on':
			$min = max( 1, min( 240, (int) ( $assoc['minutes'] ?? 30 ) ) );
			update_option( Auth\OPTION_LOG_UNTIL, time() + 60 * $min, false );
			/* translators: %d: number of minutes */
			WP_CLI::success( sprintf( __( 'Header logging enabled for %d min.', 'colorbox-wp-mcp' ), $min ) );
			break;
		case 'off':
			delete_option( Auth\OPTION_LOG_UNTIL );
			WP_CLI::success( __( 'Header logging disabled.', 'colorbox-wp-mcp' ) );
			break;
		case 'clear':
			delete_option( Auth\OPTION_LOG );
			WP_CLI::success( __( 'Header log cleared.', 'colorbox-wp-mcp' ) );
			break;
		default:
			WP_CLI::line( (string) wp_json_encode( get_option( Auth\OPTION_LOG, array() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}
}

/**
 * GitHub token for reading private repositories (fine-grained, Contents: Read-only).
 *
 * ## OPTIONS
 *
 * <action>
 * : set (token from standard input) | status | delete
 */
function github_token_command( $args ) {
	switch ( $args[0] ?? 'status' ) {
		case 'set':
			$token = trim( (string) stream_get_contents( STDIN ) );
			if ( ! preg_match( '/^(github_pat_|ghp_)[A-Za-z0-9_]{20,}$/', $token ) ) {
				WP_CLI::error( __( 'This does not look like a GitHub token (github_pat_… or ghp_…). Pass it on standard input.', 'colorbox-wp-mcp' ) );
			}
			Media\set_github_token( $token );
			WP_CLI::success( __( 'GitHub token saved (encrypted).', 'colorbox-wp-mcp' ) );
			break;
		case 'delete':
			Media\delete_github_token();
			WP_CLI::success( __( 'GitHub token deleted.', 'colorbox-wp-mcp' ) );
			break;
		default:
			$token = Media\get_github_token();
			if ( '' === $token ) {
				WP_CLI::line( __( 'No GitHub token. Only public repositories will work.', 'colorbox-wp-mcp' ) );
				return;
			}
			$r = wp_remote_get( 'https://api.github.com/user', array( 'headers' => Media\github_headers( 'application/vnd.github+json' ), 'timeout' => 15 ) );
			/* translators: 1: first characters of the token, 2: HTTP status code or error message */
			WP_CLI::line( sprintf( __( 'Token set (%1$s…), GitHub /user: HTTP %2$s', 'colorbox-wp-mcp' ), substr( $token, 0, 11 ), is_wp_error( $r ) ? $r->get_error_message() : wp_remote_retrieve_response_code( $r ) ) );
	}
}

WP_CLI::add_command( 'colorbox-mcp token', Token_Command::class );
WP_CLI::add_command( 'colorbox-mcp github-token', __NAMESPACE__ . '\\github_token_command' );
WP_CLI::add_command( 'colorbox-mcp headers', __NAMESPACE__ . '\\headers_command' );
