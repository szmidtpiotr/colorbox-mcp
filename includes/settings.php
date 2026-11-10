<?php
/**
 * Panel „Ustawienia → Agent MCP”: wszystko, co trzeba, żeby wtyczkę skonfigurować bez WP-CLI —
 * stan wymagań, konto agenta, tokeny, włączanie narzędzi, integracja z GitHubem.
 * Tylko dla administratora (manage_options); każda akcja z nonce.
 */

namespace Colorbox\WpMcp\Settings;

use Colorbox\WpMcp\Abilities;
use Colorbox\WpMcp\Auth;
use Colorbox\WpMcp\Content;
use Colorbox\WpMcp\Media;

defined( 'ABSPATH' ) || exit;

const PAGE            = 'colorbox-mcp';
const OPTION_DISABLED = 'colorbox_mcp_disabled_tools';
const CAP             = 'manage_options';

/** Na świeżej instalacji narzędzia zmieniające treści publiczne albo kasujące trwale są wyłączone. */
const DEFAULT_DISABLED = array(
	'colorbox/update-published-post',
	'colorbox/restore-post-revision',
	'colorbox/trash-post',
	'colorbox/delete-category',
	'colorbox/delete-image',
);

function boot(): void {
	add_action( 'admin_menu', __NAMESPACE__ . '\\menu' );
	add_filter( 'plugin_action_links_' . plugin_basename( \Colorbox\WpMcp\FILE ), __NAMESPACE__ . '\\action_links' );
	foreach ( array( 'create_agent', 'create_token', 'revoke_token', 'save_tools', 'save_integrations', 'delete_github' ) as $action ) {
		add_action( 'admin_post_colorbox_mcp_' . $action, __NAMESPACE__ . '\\handle_' . $action );
	}
}

/**
 * @return string[]
 */
function disabled_tools(): array {
	$value = get_option( OPTION_DISABLED, null );
	return is_array( $value ) ? array_values( array_map( 'strval', $value ) ) : DEFAULT_DISABLED;
}

/**
 * Narzędzia wystawione w serwerze MCP (wyłączone w panelu znikają z listy narzędzi klienta).
 *
 * @return string[]
 */
function enabled_tools(): array {
	return array_values( array_diff( Abilities\NAMES, disabled_tools() ) );
}

function url( string $tab = '', array $args = array() ): string {
	return add_query_arg( array_merge( array( 'page' => PAGE ), $tab ? array( 'tab' => $tab ) : array(), $args ), admin_url( 'options-general.php' ) );
}

function endpoint(): string {
	return rest_url( \Colorbox\WpMcp\ROUTE_NAMESPACE . '/' . \Colorbox\WpMcp\ROUTE );
}

function menu(): void {
	add_options_page( __( 'MCP Agent', 'colorbox-wp-mcp' ), __( 'MCP Agent', 'colorbox-wp-mcp' ), CAP, PAGE, __NAMESPACE__ . '\\render' );
}

function action_links( array $links ): array {
	array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( url() ), esc_html__( 'Settings', 'colorbox-wp-mcp' ) ) );
	return $links;
}

/** Wspólny początek każdej akcji: uprawnienie i nonce. */
function guard( string $action ): void {
	if ( ! current_user_can( CAP ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'colorbox-wp-mcp' ), 403 );
	}
	check_admin_referer( 'colorbox_mcp_' . $action );
}

function back( string $tab, string $notice, string $type = 'success' ): void {
	wp_safe_redirect( url( $tab, array( 'cbx_notice' => rawurlencode( $notice ), 'cbx_type' => $type ) ) );
	exit;
}

function form_open( string $action ): void {
	printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
	printf( '<input type="hidden" name="action" value="colorbox_mcp_%s">', esc_attr( $action ) );
	wp_nonce_field( 'colorbox_mcp_' . $action );
}

/* ---------- Akcje ---------- */

function handle_create_agent(): void {
	guard( 'create_agent' );
	$login = sanitize_user( wp_unslash( (string) ( $_POST['login'] ?? 'agent' ) ), true );
	if ( '' === $login || username_exists( $login ) ) {
		/* translators: %s: user login */
		back( 'access', sprintf( __( 'The login "%s" is empty or already taken.', 'colorbox-wp-mcp' ), $login ), 'error' );
	}
	$id = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 40, true, true ),
			'display_name' => __( 'AI Agent', 'colorbox-wp-mcp' ),
			'role'         => 'editor',
		)
	);
	if ( is_wp_error( $id ) ) {
		back( 'access', $id->get_error_message(), 'error' );
	}
	// Bez unfiltered_html WordPress filtruje treść agenta (np. usuwa <script>).
	( new \WP_User( $id ) )->add_cap( 'unfiltered_html', false );
	/* translators: %s: user login */
	back( 'access', sprintf( __( 'Account "%s" (Editor, without unfiltered_html) created. Now create a token for it.', 'colorbox-wp-mcp' ), $login ) );
}

function handle_create_token(): void {
	guard( 'create_token' );
	$user  = get_userdata( (int) ( $_POST['user_id'] ?? 0 ) );
	$label = sanitize_text_field( wp_unslash( (string) ( $_POST['label'] ?? '' ) ) );
	if ( ! $user || ! user_can( $user, 'edit_posts' ) ) {
		back( 'access', __( 'Choose a user who can edit posts.', 'colorbox-wp-mcp' ), 'error' );
	}
	if ( user_can( $user, 'manage_options' ) ) {
		back( 'access', __( 'Refused: no tokens for administrators. Use an account with the Editor role.', 'colorbox-wp-mcp' ), 'error' );
	}
	if ( '' === $label ) {
		back( 'access', __( 'Enter a token name, e.g. "perplexity".', 'colorbox-wp-mcp' ), 'error' );
	}
	$token = Auth\create_token( (int) $user->ID, $label );
	// Jawny token pokazujemy jeden raz — przez krótkotrwały transient przypisany do tego administratora.
	set_transient( 'colorbox_mcp_new_token_' . get_current_user_id(), $token['token'], 5 * MINUTE_IN_SECONDS );
	/* translators: 1: token name, 2: user login */
	back( 'access', sprintf( __( 'Token "%1$s" for %2$s created.', 'colorbox-wp-mcp' ), $label, $user->user_login ) );
}

function handle_revoke_token(): void {
	guard( 'revoke_token' );
	$ok = Auth\revoke_token( sanitize_key( (string) ( $_POST['token_id'] ?? '' ) ) );
	back(
		'access',
		$ok ? __( 'Token revoked. The client that used it has lost access.', 'colorbox-wp-mcp' ) : __( 'No such token.', 'colorbox-wp-mcp' ),
		$ok ? 'success' : 'error'
	);
}

function handle_save_tools(): void {
	guard( 'save_tools' );
	$on = array_map( 'strval', (array) ( $_POST['tools'] ?? array() ) );
	update_option( OPTION_DISABLED, array_values( array_diff( Abilities\NAMES, $on ) ), false );
	back( 'tools', __( 'Saved. Sync the tools in your client (e.g. Perplexity) so it sees the change.', 'colorbox-wp-mcp' ) );
}

function handle_save_integrations(): void {
	guard( 'save_integrations' );
	$token = trim( wp_unslash( (string) ( $_POST['github_token'] ?? '' ) ) );
	if ( '' !== $token ) {
		if ( ! preg_match( '/^(github_pat_|ghp_)[A-Za-z0-9_]{20,}$/', $token ) ) {
			back( 'integrations', __( 'This does not look like a GitHub token (github_pat_… or ghp_…).', 'colorbox-wp-mcp' ), 'error' );
		}
		Media\set_github_token( $token );
	}
	back( 'integrations', __( 'Saved.', 'colorbox-wp-mcp' ) );
}

function handle_delete_github(): void {
	guard( 'delete_github' );
	Media\delete_github_token();
	back( 'integrations', __( 'GitHub token deleted. Only public repositories will work.', 'colorbox-wp-mcp' ) );
}

/* ---------- Widok ---------- */

/**
 * Zakładki panelu: slug => etykieta. Każdy slug ma swoją funkcję render_<slug>().
 *
 * @return array<string, string>
 */
function tabs(): array {
	return array(
		'start'        => __( 'Start', 'colorbox-wp-mcp' ),
		'access'       => __( 'Agent account and tokens', 'colorbox-wp-mcp' ),
		'tools'        => __( 'Tools', 'colorbox-wp-mcp' ),
		'integrations' => 'GitHub',
	);
}

function render(): void {
	if ( ! current_user_can( CAP ) ) {
		return;
	}
	$tabs = tabs();
	$tab  = sanitize_key( (string) ( $_GET['tab'] ?? 'start' ) );
	$tab  = isset( $tabs[ $tab ] ) ? $tab : 'start';

	printf( '<div class="wrap"><h1>%s</h1>', esc_html__( 'MCP Agent', 'colorbox-wp-mcp' ) );
	if ( isset( $_GET['cbx_notice'] ) ) {
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			'error' === ( $_GET['cbx_type'] ?? '' ) ? 'error' : 'success',
			esc_html( wp_unslash( (string) $_GET['cbx_notice'] ) )
		);
	}
	echo '<nav class="nav-tab-wrapper">';
	foreach ( $tabs as $slug => $label ) {
		printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( url( $slug ) ), $slug === $tab ? ' nav-tab-active' : '', esc_html( $label ) );
	}
	echo '</nav>';
	call_user_func( __NAMESPACE__ . '\\render_' . $tab );
	echo '</div>';
}

/**
 * @return array<int, array{0:bool,1:string,2:string}> [ok, co, co zrobić]
 */
function checks(): array {
	$agents = agent_users();
	$langs  = Content\polylang_ready() ? Content\language_slugs() : array();
	$see    = __( 'See the "Agent account and tokens" tab.', 'colorbox-wp-mcp' );
	return array(
		array( version_compare( get_bloginfo( 'version' ), '6.9', '>=' ), __( 'WordPress 6.9 or newer (Abilities API)', 'colorbox-wp-mcp' ), __( 'Update WordPress.', 'colorbox-wp-mcp' ) ),
		array( Content\polylang_ready(), __( 'Polylang active', 'colorbox-wp-mcp' ), __( 'Install and activate the Polylang plugin (the language and translation tools need it).', 'colorbox-wp-mcp' ) ),
		/* translators: %s: comma-separated language slugs, or "none" */
		array( count( $langs ) > 0, sprintf( __( 'Polylang languages: %s', 'colorbox-wp-mcp' ), $langs ? implode( ', ', $langs ) : __( 'none', 'colorbox-wp-mcp' ) ), __( 'Add languages in the Languages menu.', 'colorbox-wp-mcp' ) ),
		array( (bool) get_option( 'permalink_structure' ), __( 'Pretty permalinks enabled', 'colorbox-wp-mcp' ), __( 'Settings → Permalinks: choose anything except "Plain".', 'colorbox-wp-mcp' ) ),
		array( is_ssl() || 0 === strpos( home_url(), 'https://' ), __( 'Site served over HTTPS', 'colorbox-wp-mcp' ), __( 'Clients on the internet (e.g. Perplexity) require HTTPS.', 'colorbox-wp-mcp' ) ),
		array( (bool) $agents, __( 'Agent account (Editor)', 'colorbox-wp-mcp' ), $see ),
		array( (bool) Auth\get_tokens(), __( 'At least one token', 'colorbox-wp-mcp' ), $see ),
	);
}

function render_start(): void {
	printf( '<h2>%s</h2><table class="widefat striped" style="max-width:900px"><tbody>', esc_html__( 'Status', 'colorbox-wp-mcp' ) );
	foreach ( checks() as $c ) {
		printf(
			'<tr><td style="width:2em">%s</td><td>%s</td><td>%s</td></tr>',
			$c[0] ? '✅' : '❌',
			esc_html( $c[1] ),
			$c[0] ? '' : esc_html( $c[2] )
		);
	}
	echo '</tbody></table>';

	$endpoint = endpoint();
	printf( '<h2>%s</h2>', esc_html__( 'MCP server address', 'colorbox-wp-mcp' ) );
	printf( '<p><input type="text" readonly class="large-text code" value="%s" onclick="this.select()"></p>', esc_attr( $endpoint ) );
	printf( '<p>%s</p>', esc_html__( 'A token works only on this address (not on the rest of the REST API) and signs the client in as the account it is assigned to.', 'colorbox-wp-mcp' ) );

	printf( '<h2>%s</h2><ol>', esc_html__( 'How to connect a client', 'colorbox-wp-mcp' ) );
	printf( '<li>%s</li>', wp_kses_post( __( 'In the <strong>Agent account and tokens</strong> tab, create an agent account, then a separate token for each client.', 'colorbox-wp-mcp' ) ) );
	printf( '<li>%s</li>', wp_kses_post( __( '<strong>Perplexity</strong>: Settings → Connectors → Add remote connector. Use the address above, authentication "API Key", and paste the token. After changing tools, click "Sync tools".', 'colorbox-wp-mcp' ) ) );
	printf( '<li>%s</li></ol>', wp_kses_post( __( '<strong>Claude Code</strong>: a <code>.mcp.json</code> file in the project (token in the <code>COLORBOX_MCP_TOKEN</code> environment variable):', 'colorbox-wp-mcp' ) ) );
	$json = wp_json_encode(
		array(
			'mcpServers' => array(
				'wordpress' => array(
					'type'    => 'http',
					'url'     => $endpoint,
					'headers' => array( 'Authorization' => 'Bearer ${COLORBOX_MCP_TOKEN}' ),
				),
			),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	);
	printf( '<pre style="background:#fff;padding:1em;max-width:900px;overflow:auto">%s</pre>', esc_html( $json ) );
	printf( '<p>%s</p>', esc_html__( 'In the client, set "Always ask" at least for the tools marked ⚠ in the Tools tab.', 'colorbox-wp-mcp' ) );
}

/**
 * @return \WP_User[]
 */
function agent_users(): array {
	return get_users(
		array(
			'role__in' => array( 'editor', 'author' ),
			'orderby'  => 'login',
		)
	);
}

function render_access(): void {
	$new = get_transient( 'colorbox_mcp_new_token_' . get_current_user_id() );
	if ( $new ) {
		delete_transient( 'colorbox_mcp_new_token_' . get_current_user_id() );
		printf( '<div class="notice notice-warning"><p>%s</p>', wp_kses_post( __( '<strong>New token: copy it now.</strong> Once you reload the page it cannot be shown again (only its hash is stored).', 'colorbox-wp-mcp' ) ) );
		printf( '<p><input type="text" readonly class="large-text code" value="%s" onclick="this.select()"></p></div>', esc_attr( $new ) );
	}

	printf( '<h2>%s</h2>', esc_html__( 'Agent account', 'colorbox-wp-mcp' ) );
	printf( '<p>%s</p>', wp_kses_post( __( 'The agent works on an ordinary account with the <strong>Editor</strong> role (not an administrator) and without <code>unfiltered_html</code>, so WordPress filters what it inserts. It never signs in with a password, only with a token.', 'colorbox-wp-mcp' ) ) );
	$users = agent_users();
	if ( $users ) {
		echo '<ul>';
		foreach ( $users as $u ) {
			printf(
				'<li><code>%s</code> — %s%s</li>',
				esc_html( $u->user_login ),
				esc_html( implode( ', ', array_map( fn( $r ) => translate_user_role( wp_roles()->roles[ $r ]['name'] ?? $r ), $u->roles ) ) ),
				user_can( $u, 'unfiltered_html' ) ? ' <strong>' . esc_html__( '(has unfiltered_html, not recommended)', 'colorbox-wp-mcp' ) . '</strong>' : ''
			);
		}
		echo '</ul>';
	}
	form_open( 'create_agent' );
	printf( '<p><label>%s <input type="text" name="login" value="agent" class="regular-text"></label> ', esc_html__( 'Login for the new account:', 'colorbox-wp-mcp' ) );
	submit_button( __( 'Create agent account', 'colorbox-wp-mcp' ), 'secondary', 'submit', false );
	echo '</p></form>';

	printf( '<h2>%s</h2>', esc_html__( 'Tokens', 'colorbox-wp-mcp' ) );
	$tokens = Auth\get_tokens();
	if ( $tokens ) {
		printf(
			'<table class="widefat striped" style="max-width:900px"><thead><tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th></th></tr></thead><tbody>',
			esc_html__( 'Name', 'colorbox-wp-mcp' ),
			esc_html__( 'Account', 'colorbox-wp-mcp' ),
			esc_html__( 'Created', 'colorbox-wp-mcp' ),
			esc_html__( 'Last used', 'colorbox-wp-mcp' )
		);
		foreach ( $tokens as $id => $t ) {
			$u = get_userdata( (int) $t['user_id'] );
			echo '<tr>';
			printf( '<td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>', esc_html( $t['label'] ), esc_html( $u ? $u->user_login : __( '(no account)', 'colorbox-wp-mcp' ) ), esc_html( wp_date( 'Y-m-d H:i', (int) $t['created'] ) ), esc_html( $t['last_used'] ? wp_date( 'Y-m-d H:i', (int) $t['last_used'] ) : '—' ) );
			form_open( 'revoke_token' );
			printf( '<input type="hidden" name="token_id" value="%s">', esc_attr( $id ) );
			printf(
				'<button class="button button-link-delete" onclick="return confirm(%s)">%s</button></form>',
				/* translators: %s: token name */
				esc_attr( wp_json_encode( sprintf( __( 'Revoke token "%s"? The client will lose access.', 'colorbox-wp-mcp' ), $t['label'] ) ) ),
				esc_html__( 'Revoke', 'colorbox-wp-mcp' )
			);
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	} else {
		printf( '<p>%s</p>', esc_html__( 'No tokens yet.', 'colorbox-wp-mcp' ) );
	}

	printf( '<h3>%s</h3>', esc_html__( 'New token', 'colorbox-wp-mcp' ) );
	if ( ! $users ) {
		printf( '<p>%s</p>', esc_html__( 'Create an agent account first.', 'colorbox-wp-mcp' ) );
		return;
	}
	form_open( 'create_token' );
	printf( '<p><label>%s <select name="user_id">', esc_html__( 'Account:', 'colorbox-wp-mcp' ) );
	foreach ( $users as $u ) {
		printf( '<option value="%d">%s</option>', (int) $u->ID, esc_html( $u->user_login ) );
	}
	printf( '</select></label> <label>%s <input type="text" name="label" placeholder="perplexity" class="regular-text"></label> ', esc_html__( 'Name (what it is for):', 'colorbox-wp-mcp' ) );
	submit_button( __( 'Create token', 'colorbox-wp-mcp' ), 'primary', 'submit', false );
	printf( '</p><p class="description">%s</p></form>', esc_html__( 'Use a separate token for each client, so you can revoke one without touching the others.', 'colorbox-wp-mcp' ) );
}

/**
 * @return array{0:string,1:string} [grupa, etykieta grupy]
 */
function tool_group( array $annotations ): array {
	if ( ! empty( $annotations['readonly'] ) ) {
		return array( 'read', __( 'Read-only', 'colorbox-wp-mcp' ) );
	}
	if ( ! empty( $annotations['destructive'] ) ) {
		return array( 'destructive', __( '⚠ Change published content or delete (set "Always ask" in the client)', 'colorbox-wp-mcp' ) );
	}
	return array( 'write', __( 'Write (drafts, comments, media)', 'colorbox-wp-mcp' ) );
}

function render_tools(): void {
	$disabled = disabled_tools();
	$groups   = array();
	foreach ( Abilities\NAMES as $name ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
		if ( ! $ability ) {
			continue;
		}
		$meta                  = $ability->get_meta();
		[ $key, $label ]       = tool_group( (array) ( $meta['annotations'] ?? array() ) );
		$groups[ $key ]['label'] = $label;
		$groups[ $key ]['items'][] = $ability;
	}

	printf( '<p>%s</p>', esc_html__( 'A disabled tool disappears from the client\'s tool list and cannot be called. After a change, sync the tools in the client.', 'colorbox-wp-mcp' ) );
	form_open( 'save_tools' );
	foreach ( array( 'read', 'write', 'destructive' ) as $key ) {
		if ( empty( $groups[ $key ] ) ) {
			continue;
		}
		printf( '<h2>%s</h2><table class="widefat striped" style="max-width:1100px"><tbody>', esc_html( $groups[ $key ]['label'] ) );
		foreach ( $groups[ $key ]['items'] as $ability ) {
			$name = $ability->get_name();
			printf(
				'<tr><td style="width:2em"><input type="checkbox" name="tools[]" value="%1$s" id="t-%2$s"%3$s></td><td style="width:22em"><label for="t-%2$s"><strong>%4$s</strong><br><code>%5$s</code></label></td><td>%6$s</td></tr>',
				esc_attr( $name ),
				esc_attr( sanitize_html_class( $name ) ),
				checked( ! in_array( $name, $disabled, true ), true, false ),
				esc_html( $ability->get_label() ),
				esc_html( str_replace( '/', '-', $name ) ),
				esc_html( $ability->get_description() )
			);
		}
		echo '</tbody></table>';
	}
	submit_button( __( 'Save tools', 'colorbox-wp-mcp' ) );
	echo '</form>';
}

function render_integrations(): void {
	$token = Media\get_github_token();
	printf( '<h2>%s</h2>', esc_html__( 'Why a GitHub token?', 'colorbox-wp-mcp' ) );
	printf( '<p>%s</p><ul style="list-style:disc;margin-left:2em">', esc_html__( 'Three tools read files from GitHub repositories:', 'colorbox-wp-mcp' ) );
	printf( '<li><code>colorbox-list-repo-images</code> — %s</li>', esc_html__( 'lists the images in a repository folder,', 'colorbox-wp-mcp' ) );
	printf( '<li><code>colorbox-import-image</code> %s</li>', wp_kses_post( __( 'with the <code>github</code> source — copies an image from a repository straight into the media library,', 'colorbox-wp-mcp' ) ) );
	printf( '<li><code>colorbox-github-code</code> — %s</li></ul>', esc_html__( 'inserts a code excerpt as a syntax-highlighted block with a "Source" caption.', 'colorbox-wp-mcp' ) );
	printf( '<p>%s</p>', esc_html__( 'Your site\'s server downloads the files, not the agent. That way the agent does not have to upload files or have its own access to GitHub.', 'colorbox-wp-mcp' ) );
	echo '<table class="widefat striped" style="max-width:900px"><tbody>';
	printf(
		'<tr><th style="width:12em">%s</th><td>%s</td></tr>',
		esc_html__( 'Without a token', 'colorbox-wp-mcp' ),
		esc_html__( 'Only public repositories work. GitHub then allows 60 requests per hour from the server\'s address, which a post with several images can easily use up.', 'colorbox-wp-mcp' )
	);
	printf(
		'<tr><th>%s</th><td>%s</td></tr>',
		esc_html__( 'With a token', 'colorbox-wp-mcp' ),
		esc_html__( 'Your private repositories covered by the token work too, and the limit rises to 5,000 requests per hour.', 'colorbox-wp-mcp' )
	);
	printf(
		'<tr><th>%s</th><td>%s</td></tr>',
		esc_html__( 'Security', 'colorbox-wp-mcp' ),
		wp_kses_post( __( 'A read-only token is enough. The plugin stores it encrypted with the keys from <code>wp-config.php</code>; the agent never sees or receives it. Note: code or an image from a private repository becomes public once the post containing it is published.', 'colorbox-wp-mcp' ) )
	);
	echo '</tbody></table>';

	printf( '<h3>%s</h3><ol>', esc_html__( 'How to create a token', 'colorbox-wp-mcp' ) );
	echo '<li>GitHub → <strong>Settings</strong> → <strong>Developer settings</strong> → <strong>Personal access tokens</strong> → <strong>Fine-grained tokens</strong> → <strong>Generate new token</strong>.</li>';
	printf( '<li><strong>Repository access</strong>: %s</li>', esc_html__( 'the selected repositories (or all of them, if the agent should be able to write about any project).', 'colorbox-wp-mcp' ) );
	printf( '<li><strong>Permissions → Repository permissions → Contents: Read-only</strong>. %s</li>', esc_html__( 'Do not select anything else.', 'colorbox-wp-mcp' ) );
	printf( '<li>%s</li></ol>', wp_kses_post( __( 'Copy the token (it starts with <code>github_pat_</code>) and paste it below.', 'colorbox-wp-mcp' ) ) );

	printf(
		'<p>%s <strong>%s</strong></p>',
		esc_html__( 'Status:', 'colorbox-wp-mcp' ),
		/* translators: %s: first characters of the GitHub token */
		$token ? esc_html( sprintf( __( 'set (%s…)', 'colorbox-wp-mcp' ), substr( $token, 0, 11 ) ) ) : esc_html__( 'not set, only public repositories work', 'colorbox-wp-mcp' )
	);

	form_open( 'save_integrations' );
	echo '<table class="form-table"><tbody>';
	printf(
		'<tr><th><label for="github_token">%s</label></th><td><input type="password" id="github_token" name="github_token" class="regular-text code" autocomplete="off" placeholder="github_pat_…"><p class="description">%s</p></td></tr>',
		$token ? esc_html__( 'New GitHub token', 'colorbox-wp-mcp' ) : esc_html__( 'GitHub token', 'colorbox-wp-mcp' ),
		esc_html__( 'Leave empty to keep the current one.', 'colorbox-wp-mcp' )
	);
	echo '</tbody></table>';
	submit_button( __( 'Save', 'colorbox-wp-mcp' ) );
	echo '</form>';

	if ( $token ) {
		form_open( 'delete_github' );
		submit_button( __( 'Delete GitHub token', 'colorbox-wp-mcp' ), 'delete', 'submit', false );
		echo '</form>';
	}
}
