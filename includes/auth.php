<?php
/**
 * Uwierzytelnienie tokenem Bearer, limit zapytań i tymczasowy podgląd nagłówków.
 *
 * Token jest przypisany do jednego użytkownika WordPressa i działa WYŁĄCZNIE na endpoincie MCP tej wtyczki
 * (/wp-json/colorbox/mcp). Pozostałe REST API go ignoruje, więc wyciek tokenu nie daje dostępu do
 * /wp/v2 (kasowanie, publikacja, media). W bazie trzymamy tylko skrót SHA-256 tokenu.
 */

namespace Colorbox\WpMcp\Auth;

use WP_Error;

defined( 'ABSPATH' ) || exit;

const OPTION_TOKENS     = 'colorbox_mcp_tokens';
const OPTION_LOG_UNTIL  = 'colorbox_mcp_header_log_until';
const OPTION_LOG        = 'colorbox_mcp_header_log';
const TOKEN_PREFIX      = 'cbx_';
const RATE_WINDOW       = 60;  // sekundy
const RATE_LIMIT_USER   = 120; // zapytań na okno dla zalogowanego tokenem
const RATE_LIMIT_ANON   = 30;  // zapytań na okno bez tokenu (z jednego IP)
const FAIL_WINDOW       = 600;
const FAIL_LIMIT        = 10;  // błędnych tokenów z jednego IP na FAIL_WINDOW
const LOG_MAX_ENTRIES   = 50;

/** @var string|null Stan tokenu w bieżącym zapytaniu: null = brak, 'ok', 'invalid'. */
$GLOBALS['colorbox_mcp_token_state'] = null;

function boot(): void {
	add_filter( 'determine_current_user', __NAMESPACE__ . '\\determine_current_user', 30 );
	add_filter( 'rest_authentication_errors', __NAMESPACE__ . '\\rest_authentication_errors', 30 );
	add_filter( 'rest_post_dispatch', __NAMESPACE__ . '\\add_www_authenticate', 10, 3 );
}

/**
 * Czy bieżące zapytanie HTTP idzie na endpoint MCP tej wtyczki.
 */
function is_mcp_request(): bool {
	$route = '/' . \Colorbox\WpMcp\ROUTE_NAMESPACE . '/' . \Colorbox\WpMcp\ROUTE;

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ) {
		return 0 === strpos( untrailingslashit( wp_unslash( $_GET['rest_route'] ) ), $route );
	}

	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
	$base = '/' . trim( rest_get_url_prefix(), '/' ) . $route;

	return $path === $base || 0 === strpos( $path, $base . '/' );
}

/**
 * Wyciąga token z nagłówka. Kolejność: Authorization: Bearer, potem X-API-Key (na wypadek klientów
 * typu „API Key”, które nie używają Bearer). Nigdy z parametru URL — trafiłby do logów proxy.
 */
function extract_token(): ?string {
	$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
	if ( is_string( $auth ) && preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m ) ) {
		return $m[1];
	}

	$key = $_SERVER['HTTP_X_API_KEY'] ?? '';
	if ( is_string( $key ) && '' !== trim( $key ) ) {
		return trim( $key );
	}

	return null;
}

function hash_token( string $token ): string {
	return hash( 'sha256', $token );
}

/**
 * @return array<string, array{hash:string,user_id:int,label:string,created:int,last_used:int}>
 */
function get_tokens(): array {
	$tokens = get_option( OPTION_TOKENS, array() );
	return is_array( $tokens ) ? $tokens : array();
}

/**
 * Tworzy token dla użytkownika. Zwraca jawny token (pokazywany raz) i jego identyfikator.
 *
 * @return array{id:string,token:string}
 */
function create_token( int $user_id, string $label ): array {
	$token  = TOKEN_PREFIX . bin2hex( random_bytes( 32 ) );
	$id     = bin2hex( random_bytes( 4 ) );
	$tokens = get_tokens();

	$tokens[ $id ] = array(
		'hash'      => hash_token( $token ),
		'user_id'   => $user_id,
		'label'     => $label,
		'created'   => time(),
		'last_used' => 0,
	);
	update_option( OPTION_TOKENS, $tokens, false );

	return array(
		'id'    => $id,
		'token' => $token,
	);
}

function revoke_token( string $id ): bool {
	$tokens = get_tokens();
	if ( ! isset( $tokens[ $id ] ) ) {
		return false;
	}
	unset( $tokens[ $id ] );
	update_option( OPTION_TOKENS, $tokens, false );
	return true;
}

/**
 * Zwraca ID użytkownika dla poprawnego tokenu albo 0.
 */
function verify_token( string $token ): int {
	if ( 0 !== strpos( $token, TOKEN_PREFIX ) ) {
		return 0;
	}

	$hash   = hash_token( $token );
	$tokens = get_tokens();

	foreach ( $tokens as $id => $data ) {
		if ( ! hash_equals( (string) $data['hash'], $hash ) ) {
			continue;
		}

		$user = get_userdata( (int) $data['user_id'] );
		if ( ! $user ) {
			return 0;
		}

		// Zapis „ostatnio użyty” najwyżej co 5 minut, żeby nie pisać do bazy przy każdym zapytaniu.
		if ( time() - (int) $data['last_used'] > 300 ) {
			$tokens[ $id ]['last_used'] = time();
			update_option( OPTION_TOKENS, $tokens, false );
		}

		return (int) $user->ID;
	}

	return 0;
}

function determine_current_user( $user_id ) {
	if ( ! is_mcp_request() ) {
		return $user_id;
	}

	maybe_log_headers();

	$token = extract_token();
	if ( null === $token ) {
		return $user_id;
	}

	$found = verify_token( $token );
	if ( $found ) {
		$GLOBALS['colorbox_mcp_token_state'] = 'ok';
		return $found;
	}

	$GLOBALS['colorbox_mcp_token_state'] = 'invalid';
	record_failure();
	return $user_id;
}

/**
 * Błędny token → 401, przekroczony limit → 429. Działa tylko na endpoincie MCP.
 */
function rest_authentication_errors( $result ) {
	if ( ! is_mcp_request() ) {
		return $result;
	}

	$ip = client_ip();

	// Blokada po błędnych tokenach nie dotyczy poprawnego tokenu: z LAN cały ruch przychodzi
	// przez NAT z jednego adresu routera, więc inaczej jedna pomyłka odcięłaby wszystkich.
	if ( 'ok' !== $GLOBALS['colorbox_mcp_token_state'] && too_many_failures( $ip ) ) {
		return rate_limited_error( FAIL_WINDOW );
	}

	if ( 'invalid' === $GLOBALS['colorbox_mcp_token_state'] ) {
		return new WP_Error( 'colorbox_mcp_invalid_token', __( 'Invalid token.', 'colorbox-wp-mcp' ), array( 'status' => 401 ) );
	}

	$user_id = get_current_user_id();
	$key     = $user_id ? 'u' . $user_id : 'ip' . md5( $ip );
	$limit   = $user_id ? RATE_LIMIT_USER : RATE_LIMIT_ANON;

	if ( ! hit_counter( 'rl_' . $key, RATE_WINDOW, $limit ) ) {
		return rate_limited_error( RATE_WINDOW );
	}

	return $result;
}

function rate_limited_error( int $retry_after ): WP_Error {
	if ( ! headers_sent() ) {
		header( 'Retry-After: ' . $retry_after );
	}
	return new WP_Error( 'colorbox_mcp_rate_limited', __( 'Too many requests. Try again shortly.', 'colorbox-wp-mcp' ), array( 'status' => 429 ) );
}

/**
 * Atomowy licznik w stałym oknie czasowym, trzymany w tabeli opcji (bez cache obiektów transienty
 * gubią zliczenia przy równoległych zapytaniach). Zwraca stan licznika po zwiększeniu o $add.
 */
function bump_counter( string $key, int $window, int $add = 1 ): int {
	global $wpdb;

	$name = 'colorbox_mcp_c_' . $key . '_' . intdiv( time(), $window );

	if ( $add > 0 ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %d, 'off')
				ON DUPLICATE KEY UPDATE option_value = option_value + %d",
				$name,
				$add,
				$add
			)
		);
	}

	// Sprzątanie starych okien przy ok. 1% zapytań.
	if ( 1 === wp_rand( 1, 100 ) ) {
		cleanup_counters();
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
}

function cleanup_counters(): void {
	global $wpdb;
	$like = $wpdb->esc_like( 'colorbox_mcp_c_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
	$now   = time();
	foreach ( $names as $name ) {
		if ( ! preg_match( '/_(\\d+)$/', $name, $m ) ) {
			continue;
		}
		$window = 0 === strpos( $name, 'colorbox_mcp_c_fail_' ) ? FAIL_WINDOW : RATE_WINDOW;
		if ( (int) $m[1] < intdiv( $now, $window ) - 1 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
		}
	}
}

function hit_counter( string $key, int $window, int $limit ): bool {
	return bump_counter( $key, $window ) <= $limit;
}

function record_failure(): void {
	// determine_current_user potrafi przyjść dwa razy w jednym zapytaniu — liczymy raz.
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	bump_counter( 'fail_' . md5( client_ip() ), FAIL_WINDOW );
}

function too_many_failures( string $ip ): bool {
	return bump_counter( 'fail_' . md5( $ip ), FAIL_WINDOW, 0 ) > FAIL_LIMIT;
}

/**
 * REMOTE_ADDR jest już podmieniony na X-Real-IP z NPM w wp-config.
 */
function client_ip(): string {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
}

/**
 * Do odpowiedzi 401 z endpointu MCP dokłada WWW-Authenticate (wymaga tego specyfikacja HTTP i MCP).
 */
function add_www_authenticate( $response, $server, $request ) {
	if ( ! $response instanceof \WP_REST_Response || 401 !== $response->get_status() ) {
		return $response;
	}

	$route = '/' . \Colorbox\WpMcp\ROUTE_NAMESPACE . '/' . \Colorbox\WpMcp\ROUTE;
	if ( 0 !== strpos( $request->get_route(), $route ) ) {
		return $response;
	}

	$value = 'Bearer realm="colorbox-mcp"';
	if ( 'invalid' === $GLOBALS['colorbox_mcp_token_state'] ) {
		$value .= ', error="invalid_token"';
	}
	$response->header( 'WWW-Authenticate', $value );

	return $response;
}

/**
 * Tymczasowy podgląd nagłówków (włączany z WP-CLI na kilkadziesiąt minut). Zapisuje TYLKO nazwy nagłówków,
 * schemat z Authorization (np. „Bearer”) i długości wartości. Żadnych wartości, żadnych sekretów.
 */
function maybe_log_headers(): void {
	$until = (int) get_option( OPTION_LOG_UNTIL, 0 );
	if ( $until < time() ) {
		return;
	}

	$names = array();
	foreach ( $_SERVER as $k => $v ) {
		if ( 0 === strpos( $k, 'HTTP_' ) ) {
			$names[ strtolower( str_replace( '_', '-', substr( $k, 5 ) ) ) ] = is_string( $v ) ? strlen( $v ) : 0;
		}
	}
	ksort( $names );

	$auth   = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
	$scheme = '';
	if ( is_string( $auth ) && '' !== $auth ) {
		$parts  = preg_split( '/\s+/', trim( $auth ), 2 );
		$scheme = $parts[0] . ' (data length: ' . strlen( $parts[1] ?? '' ) . ')';
	}

	$log   = get_option( OPTION_LOG, array() );
	$log   = is_array( $log ) ? $log : array();
	$log[] = array(
		'time'       => gmdate( 'c' ),
		'method'     => isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '',
		'ip'         => client_ip(),
		'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) $_SERVER['HTTP_USER_AGENT'], 0, 200 ) : '',
		'auth'       => $scheme,
		'headers'    => $names,
	);
	update_option( OPTION_LOG, array_slice( $log, -LOG_MAX_ENTRIES ), false );
}
