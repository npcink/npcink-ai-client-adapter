<?php
/**
 * AI client connection admin page.
 *
 * @package NpcinkOpenClawAdapter
 */

namespace Npcink\OpenClawAdapter\Admin;

use Npcink\OpenClawAdapter\Rest\Controller;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a read-only connection handoff surface for AI clients.
 */
final class Connection_Page {
	const PARENT_MENU_SLUG = 'npcink-ai';
	const MENU_SLUG        = 'npcink-ai-client-adapter';
	const MENU_CAPABILITY  = 'manage_options';
	const CREATE_ACTION    = 'npcink_openclaw_adapter_create_openclaw_password';
	const PAIR_MENU_SLUG   = 'npcink-openclaw-adapter-pair';
	const PAIR_ACTION      = 'npcink_openclaw_adapter_pairing_decision';
	const REVOKE_KEY_ACTION = 'npcink_openclaw_adapter_revoke_client_key';
	const DATETIME_DISPLAY_FORMAT = 'Y-m-d H:i:s';
	const LOCAL_CLI_PACKAGE = '@npcink/openclaw-adapter-cli@0.2.0';
	const APPLICATION_PASSWORD_FALLBACK_CONFIRM_FIELD = 'confirm_application_password_fallback';

	/**
	 * Admin page hook suffixes that should receive Adapter assets.
	 *
	 * @var array<int,string>
	 */
	private $admin_page_hooks = array();

	/**
	 * Registers the menu item.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->has_parent_menu() ) {
			$connection_hook = add_submenu_page(
				self::PARENT_MENU_SLUG,
				__( 'Npcink AI Client Adapter', 'npcink-ai-client-adapter' ),
				__( 'Adapter', 'npcink-ai-client-adapter' ),
				self::MENU_CAPABILITY,
				self::MENU_SLUG,
				array( $this, 'render' ),
				20
			);
		} else {
			$connection_hook = add_options_page(
				__( 'Npcink AI Client Adapter', 'npcink-ai-client-adapter' ),
				__( 'Npcink AI Client Adapter', 'npcink-ai-client-adapter' ),
				self::MENU_CAPABILITY,
				self::MENU_SLUG,
				array( $this, 'render' )
			);
		}

		$pairing_hook = add_submenu_page(
			null,
			__( 'Approve Npcink Client', 'npcink-ai-client-adapter' ),
			__( 'Approve Npcink Client', 'npcink-ai-client-adapter' ),
			self::MENU_CAPABILITY,
			self::PAIR_MENU_SLUG,
			array( $this, 'render_pairing_page' )
		);

		$this->admin_page_hooks = array_values( array_filter( array( $connection_hook, $pairing_hook ), 'is_string' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Enqueues Adapter admin assets on owned admin pages.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->admin_page_hooks, true ) ) {
			return;
		}

		wp_enqueue_style(
			'npcink-openclaw-adapter-admin',
			plugins_url( 'assets/admin.css', NPCINK_OPENCLAW_ADAPTER_FILE ),
			array(),
			NPCINK_OPENCLAW_ADAPTER_VERSION
		);
		wp_enqueue_script(
			'npcink-openclaw-adapter-admin',
			plugins_url( 'assets/admin.js', NPCINK_OPENCLAW_ADAPTER_FILE ),
			array(),
			NPCINK_OPENCLAW_ADAPTER_VERSION,
			true
		);
	}

	/**
	 * Returns whether another Npcink plugin already created the parent menu.
	 *
	 * @return bool
	 */
	private function has_parent_menu(): bool {
		global $menu;

		foreach ( (array) $menu as $item ) {
			if ( isset( $item[2] ) && self::PARENT_MENU_SLUG === $item[2] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the active Adapter page URL.
	 *
	 * @return string
	 */
	private function connection_page_url(): string {
		$parent = defined( 'NPCINK_TOOLBOX_VERSION' ) ? 'admin.php' : 'options-general.php';
		return admin_url( $parent . '?page=' . self::MENU_SLUG );
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'npcink-ai-client-adapter' ) );
		}

		$health                    = $this->health();
		$status                    = $this->status( $health );
		$can_create_password       = $this->can_create_application_password();
		$include_local_tls         = $this->is_local_url( home_url() );
		$local_cli_connect_command = $this->local_cli_connect_command( $include_local_tls );
		$key_records               = ( new Controller() )->admin_client_keys( get_current_user_id() );
		$active_key_count          = $this->active_key_pair_count( $key_records );
		$site_host                 = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$site_host                 = '' !== $site_host ? $site_host : home_url();
		?>
		<div
			class="wrap npcink-openclaw-adapter-connection"
			data-maa-copied-label="<?php echo esc_attr__( 'Copied', 'npcink-ai-client-adapter' ); ?>"
			data-maa-copy-failed-label="<?php echo esc_attr__( 'Copy failed', 'npcink-ai-client-adapter' ); ?>"
		>
			<h1><?php echo esc_html( 'Npcink AI Client Adapter' ); ?></h1>
			<p class="description"><?php echo esc_html__( 'Connect this WordPress site to OpenClaw or other local AI clients.', 'npcink-ai-client-adapter' ); ?></p>

			<div class="maa-summary">
				<div class="maa-summary-item">
					<span class="maa-label"><?php echo esc_html__( 'Status', 'npcink-ai-client-adapter' ); ?></span>
					<span class="maa-status maa-status-<?php echo esc_attr( $status['level'] ); ?>"><?php echo esc_html( $status['label'] ); ?></span>
				</div>
				<div class="maa-summary-item">
					<span class="maa-label"><?php echo esc_html__( 'Site', 'npcink-ai-client-adapter' ); ?></span>
					<span class="maa-value"><?php echo esc_html( $site_host ); ?></span>
				</div>
				<div class="maa-summary-item">
					<span class="maa-label"><?php echo esc_html__( 'Active devices', 'npcink-ai-client-adapter' ); ?></span>
					<span class="maa-value"><?php echo esc_html( (string) $active_key_count ); ?></span>
				</div>
			</div>
			<?php if ( empty( $health['dependencies_ready'] ) && ! empty( $health['missing_dependencies'] ) && is_array( $health['missing_dependencies'] ) ) : ?>
				<div class="notice notice-warning">
					<p>
						<strong><?php echo esc_html__( 'Suite dependencies need attention.', 'npcink-ai-client-adapter' ); ?></strong>
						<?php echo esc_html( implode( ', ', array_map( 'sanitize_text_field', $health['missing_dependencies'] ) ) ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div id="maa-connect" class="maa-method-grid">
				<section class="maa-section maa-method-card maa-method-card-recommended">
					<div class="maa-section-heading">
						<div>
							<h2>
								<?php echo esc_html__( 'Secure key pairing', 'npcink-ai-client-adapter' ); ?>
								<span class="maa-status maa-status-ok maa-heading-badge"><?php echo esc_html__( 'Recommended', 'npcink-ai-client-adapter' ); ?></span>
							</h2>
							<p class="maa-section-intro"><?php echo esc_html__( 'Recommended path: pair a local signed key so the client never receives a WordPress Application Password.', 'npcink-ai-client-adapter' ); ?></p>
						</div>
					</div>
					<div class="maa-action-row maa-command-row-primary">
						<textarea id="maa-local-cli-connect-command" hidden readonly><?php echo esc_textarea( $local_cli_connect_command ); ?></textarea>
						<button type="button" class="button button-primary maa-copy-button" data-maa-copy-target="maa-local-cli-connect-command"><?php echo esc_html__( 'Copy connect command', 'npcink-ai-client-adapter' ); ?></button>
						<button type="button" class="button" data-maa-open-target="maa-authorized-devices"><?php echo esc_html__( 'Manage devices', 'npcink-ai-client-adapter' ); ?></button>
					</div>
					<p class="description maa-action-hint"><?php echo esc_html__( 'Run this command on the computer or terminal where OpenClaw is running. Adapter stores only the approved public key.', 'npcink-ai-client-adapter' ); ?></p>
					<details id="maa-authorized-devices" class="maa-inline-disclosure maa-device-manager">
						<summary>
							<span class="maa-disclosure-copy">
								<strong><?php echo esc_html__( 'Active devices', 'npcink-ai-client-adapter' ); ?></strong>
								<span class="description"><?php echo esc_html( $this->key_pair_summary_text( $key_records ) ); ?></span>
							</span>
							<span class="maa-disclosure-icon" aria-hidden="true"></span>
						</summary>
						<p class="description"><?php echo esc_html__( 'Revoke a device when it is no longer used or was approved by mistake. Revoked devices must pair again before they can connect.', 'npcink-ai-client-adapter' ); ?></p>
						<?php $this->render_key_pair_clients_table( $key_records ); ?>
					</details>

					<details class="maa-inline-disclosure maa-backup-connection">
						<summary>
							<span class="maa-disclosure-copy">
								<strong><?php echo esc_html__( 'Fallback: WordPress Application Password connection', 'npcink-ai-client-adapter' ); ?></strong>
								<span class="description"><?php echo esc_html__( 'Use only when the client has a dedicated secret field.', 'npcink-ai-client-adapter' ); ?></span>
							</span>
							<span class="maa-disclosure-icon" aria-hidden="true"></span>
						</summary>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::CREATE_ACTION ); ?>" />
							<?php wp_nonce_field( self::CREATE_ACTION ); ?>
							<p>
								<label for="npcink-openclaw-adapter-password-name-compact"><span class="maa-label"><?php echo esc_html__( 'Application name', 'npcink-ai-client-adapter' ); ?></span></label>
								<input id="npcink-openclaw-adapter-password-name-compact" class="regular-text" type="text" name="application_name" value="AI client via Npcink AI Client Adapter" />
							</p>
							<div class="maa-option">
								<label>
									<input type="checkbox" name="include_local_tls" value="1" <?php checked( $this->is_local_url( home_url() ) ); ?> />
									<span><?php echo esc_html__( 'Include LocalWP TLS setting', 'npcink-ai-client-adapter' ); ?></span>
								</label>
								<p class="description"><?php echo esc_html__( 'LocalWP TLS option. Use only for localhost or .local testing.', 'npcink-ai-client-adapter' ); ?></p>
							</div>
							<div class="maa-option">
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::APPLICATION_PASSWORD_FALLBACK_CONFIRM_FIELD ); ?>" value="1" required />
									<span><?php echo esc_html__( 'I understand this fallback creates a WordPress Application Password and the signed key-pair connection is preferred.', 'npcink-ai-client-adapter' ); ?></span>
								</label>
							</div>
							<p class="maa-form-actions">
								<button type="submit" class="button" <?php disabled( ! $can_create_password ); ?>>
									<?php echo esc_html__( 'Create Application Password connection', 'npcink-ai-client-adapter' ); ?>
								</button>
							</p>
							<?php if ( ! $can_create_password ) : ?>
								<p class="description"><?php echo esc_html( $this->application_password_unavailable_message() ); ?></p>
							<?php endif; ?>
						</form>
						<p class="description"><?php echo esc_html__( 'The password is shown once. Store it only in the client secret field.', 'npcink-ai-client-adapter' ); ?></p>
					</details>
				</section>
			</div>

		</div>
		<?php
	}

	/**
	 * Renders the WordPress admin device pairing approval page.
	 *
	 * @return void
	 */
	public function render_pairing_page(): void {
		if ( ! current_user_can( self::MENU_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to approve device pairing.', 'npcink-ai-client-adapter' ) );
		}

		$user_code   = strtoupper( $this->request_text_field( INPUT_GET, 'user_code' ) );
		$pairing     = ( new Controller() )->admin_device_pairing( $user_code );
		$client      = is_array( $pairing['client'] ?? null ) ? $pairing['client'] : array();
		$key         = is_array( $pairing['key'] ?? null ) ? $pairing['key'] : array();
		$scopes      = is_array( $pairing['scopes'] ?? null ) ? $pairing['scopes'] : array();
		$status      = (string) ( $pairing['status'] ?? 'pending' );
		$broker      = trim( (string) ( $client['broker'] ?? '' ) . ' ' . (string) ( $client['broker_version'] ?? '' ) );
		$admin_label = (string) ( $pairing['admin_label'] ?? '' );
		?>
		<div class="wrap npcink-openclaw-adapter-connection" data-maa-copied-label="<?php echo esc_attr__( 'Copied', 'npcink-ai-client-adapter' ); ?>">
			<h1><?php echo esc_html( $this->pairing_page_title( $status ) ); ?></h1>
			<?php if ( empty( $pairing ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html__( 'Device pairing code was not found or has expired.', 'npcink-ai-client-adapter' ); ?></p></div>
			<?php else : ?>
				<?php if ( 'approved' === $status ) : ?>
					<div class="notice notice-success">
						<p><strong><?php echo esc_html__( 'Connection approved.', 'npcink-ai-client-adapter' ); ?></strong></p>
						<p><?php echo esc_html__( 'Return to the terminal or local AI client that started pairing. Wait for it to report ready; this browser page can be closed. Adapter stores only the public key; the private key was never sent to WordPress.', 'npcink-ai-client-adapter' ); ?></p>
					</div>
				<p class="description"><?php echo esc_html__( 'If you did not start this pairing, revoke this client from the Adapter page immediately.', 'npcink-ai-client-adapter' ); ?> <a href="<?php echo esc_url( $this->connection_page_url() ); ?>"><?php echo esc_html__( 'Manage paired clients', 'npcink-ai-client-adapter' ); ?></a></p>
				<?php elseif ( 'rejected' === $status ) : ?>
					<div class="notice notice-warning">
						<p><strong><?php echo esc_html__( 'Connection rejected.', 'npcink-ai-client-adapter' ); ?></strong></p>
						<p><?php echo esc_html__( 'Return to the terminal or local AI client. The client will stop polling with a rejected status.', 'npcink-ai-client-adapter' ); ?></p>
					</div>
				<?php else : ?>
					<p><?php echo esc_html__( 'Approve this local AI client only if you initiated the connection. Adapter stores only the public key; the private key stays on your computer.', 'npcink-ai-client-adapter' ); ?></p>
					<?php endif; ?>
					<table class="widefat striped maa-pairing-summary">
						<tbody>
							<?php if ( '' !== $admin_label ) : ?>
								<tr><th scope="row"><?php echo esc_html__( 'Device note', 'npcink-ai-client-adapter' ); ?></th><td><?php echo esc_html( $admin_label ); ?></td></tr>
							<?php endif; ?>
							<tr><th scope="row"><?php echo esc_html__( 'Client', 'npcink-ai-client-adapter' ); ?></th><td><?php echo esc_html( (string) ( $client['name'] ?? '' ) ); ?></td></tr>
						<tr><th scope="row"><?php echo esc_html__( 'Device reported by client', 'npcink-ai-client-adapter' ); ?></th><td><?php echo esc_html( (string) ( $client['device_name'] ?? '' ) ); ?></td></tr>
						<tr><th scope="row"><?php echo esc_html__( 'Local verifier', 'npcink-ai-client-adapter' ); ?></th><td><?php echo esc_html( $broker ); ?></td></tr>
						<tr><th scope="row"><?php echo esc_html( 'pending' === $status ? __( 'User code', 'npcink-ai-client-adapter' ) : __( 'Used pairing code', 'npcink-ai-client-adapter' ) ); ?></th><td><code><?php echo esc_html( $user_code ); ?></code></td></tr>
						<tr><th scope="row"><?php echo esc_html__( 'Started', 'npcink-ai-client-adapter' ); ?></th><td><?php echo esc_html( $this->display_datetime( (string) ( $pairing['created_at'] ?? '' ) ) ); ?></td></tr>
						<?php if ( 'approved' === $status ) : ?>
							<tr><th scope="row"><?php echo esc_html__( 'Approved', 'npcink-ai-client-adapter' ); ?></th><td><?php echo esc_html( $this->display_datetime( (string) ( $pairing['approved_at'] ?? '' ) ) ); ?></td></tr>
						<?php endif; ?>
						<?php if ( 'pending' === $status ) : ?>
							<tr><th scope="row"><?php echo esc_html__( 'Expires', 'npcink-ai-client-adapter' ); ?></th><td><?php echo esc_html( $this->display_datetime( gmdate( 'c', (int) ( $pairing['expires_at'] ?? 0 ) ) ) ); ?></td></tr>
						<?php endif; ?>
					</tbody>
				</table>
				<div class="maa-section maa-section-highlight maa-pairing-access">
					<h2><?php echo esc_html( 'approved' === $status ? __( 'Approved access', 'npcink-ai-client-adapter' ) : __( 'Requested access', 'npcink-ai-client-adapter' ) ); ?></h2>
					<ul>
						<?php foreach ( $this->pairing_scope_descriptions( $scopes ) as $scope_description ) : ?>
							<li><?php echo esc_html( $scope_description ); ?></li>
						<?php endforeach; ?>
					</ul>
					<p><?php echo esc_html__( 'This approval does not grant direct WordPress write, approval, publish, provider credential, prompt, model routing, or production workload execution authority. Writes must still go through Core proposal, approval, and commit-preflight; Adapter remains the client channel.', 'npcink-ai-client-adapter' ); ?></p>
				</div>
				<details class="maa-section maa-pairing-diagnostics">
					<summary>
						<strong><?php echo esc_html__( 'Diagnostic details', 'npcink-ai-client-adapter' ); ?></strong>
						<span><?php echo esc_html__( 'IDs and fingerprints for support; these are not client setup secrets.', 'npcink-ai-client-adapter' ); ?></span>
					</summary>
					<p class="description"><?php echo esc_html__( 'Do not paste these values into chat, tool commands, proposal payloads, or setup text unless a support workflow explicitly asks for diagnostic identifiers.', 'npcink-ai-client-adapter' ); ?></p>
					<table class="widefat striped">
						<tbody>
							<tr><th scope="row"><?php echo esc_html__( 'Public key fingerprint', 'npcink-ai-client-adapter' ); ?></th><td><code><?php echo esc_html( (string) ( $key['fingerprint'] ?? '' ) ); ?></code></td></tr>
							<tr><th scope="row"><?php echo esc_html__( 'Scope IDs', 'npcink-ai-client-adapter' ); ?></th><td><code><?php echo esc_html( implode( ', ', $scopes ) ); ?></code></td></tr>
							<?php if ( 'approved' === $status ) : ?>
								<tr><th scope="row"><?php echo esc_html__( 'Connection record ID', 'npcink-ai-client-adapter' ); ?></th><td><code><?php echo esc_html( (string) ( $pairing['connection_id'] ?? '' ) ); ?></code></td></tr>
								<tr><th scope="row"><?php echo esc_html__( 'Client key record ID', 'npcink-ai-client-adapter' ); ?></th><td><code><?php echo esc_html( (string) ( $pairing['key_id'] ?? '' ) ); ?></code></td></tr>
							<?php endif; ?>
						</tbody>
					</table>
					</details>
					<?php if ( 'pending' === $status ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="maa-pairing-form">
							<p>
								<label for="npcink-openclaw-adapter-admin-label"><span class="maa-label"><?php echo esc_html__( 'Device note', 'npcink-ai-client-adapter' ); ?></span></label>
								<input id="npcink-openclaw-adapter-admin-label" class="regular-text" type="text" name="admin_label" maxlength="80" autocomplete="off" placeholder="<?php echo esc_attr__( 'Example: Muze MacBook or office OpenClaw', 'npcink-ai-client-adapter' ); ?>" />
								<span class="description"><?php echo esc_html__( 'Optional administrator-only label for later management. It is not used for authentication or authorization. Do not enter passwords, tokens, private keys, or local file paths.', 'npcink-ai-client-adapter' ); ?></span>
							</p>
							<input type="hidden" name="action" value="<?php echo esc_attr( self::PAIR_ACTION ); ?>" />
						<input type="hidden" name="user_code" value="<?php echo esc_attr( $user_code ); ?>" />
						<?php wp_nonce_field( self::PAIR_ACTION . '_' . $user_code ); ?>
						<button type="submit" name="decision" value="approve" class="button button-primary"><?php echo esc_html__( 'Approve connection', 'npcink-ai-client-adapter' ); ?></button>
						<button type="submit" name="decision" value="reject" class="button"><?php echo esc_html__( 'Reject', 'npcink-ai-client-adapter' ); ?></button>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</div>
			<?php
		}

		/**
		 * Returns the page title for the current pairing state.
		 *
		 * @param string $status Pairing status.
		 * @return string
		 */
		private function pairing_page_title( string $status ): string {
			if ( 'approved' === $status ) {
				return __( 'Npcink client approved', 'npcink-ai-client-adapter' );
			}

			if ( 'rejected' === $status ) {
				return __( 'Npcink client rejected', 'npcink-ai-client-adapter' );
			}

			return __( 'Approve Npcink Client', 'npcink-ai-client-adapter' );
		}

		/**
		 * Returns administrator-readable scope descriptions.
		 *
		 * @param array<int,mixed> $scopes Pairing scopes.
		 * @return array<int,string>
		 */
		private function pairing_scope_descriptions( array $scopes ): array {
			$known = array(
				'npcink.read'    => __( 'Read approved Adapter and WordPress Abilities API routes.', 'npcink-ai-client-adapter' ),
				'npcink.propose' => __( 'Create Core-governed proposals for reviewed writes.', 'npcink-ai-client-adapter' ),
				'npcink.status'  => __( 'Check Adapter, Core proposal, and execution status.', 'npcink-ai-client-adapter' ),
				'npcink.execute' => __( 'Execute approved Adapter write routes only after Core approval and commit preflight.', 'npcink-ai-client-adapter' ),
			);

			$descriptions = array();
			foreach ( $scopes as $scope ) {
				$scope = (string) $scope;
				if ( isset( $known[ $scope ] ) ) {
					$descriptions[] = $known[ $scope ];
				}
			}

			if ( empty( $descriptions ) ) {
				$descriptions[] = __( 'No recognized Adapter scopes were requested.', 'npcink-ai-client-adapter' );
			}

			return $descriptions;
		}

		/**
		 * Handles device pairing approval or rejection.
		 *
	 * @return void
	 */
	public function handle_pairing_decision(): void {
		if ( ! current_user_can( self::MENU_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to approve device pairing.', 'npcink-ai-client-adapter' ) );
		}

		$user_code = isset( $_POST['user_code'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_POST['user_code'] ) ) ) : '';
		check_admin_referer( self::PAIR_ACTION . '_' . $user_code );

		$decision   = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( (string) $_POST['decision'] ) ) : '';
		$controller = new Controller();
		if ( 'approve' === $decision ) {
			$admin_label = isset( $_POST['admin_label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['admin_label'] ) ) : '';
			$result      = $controller->approve_device_pairing( $user_code, $admin_label );
			if ( is_wp_error( $result ) ) {
				wp_die( esc_html( $result->get_error_message() ) );
			}
			$result_status = 'approved';
		} else {
			$controller->reject_device_pairing( $user_code );
			$result_status = 'rejected';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::PAIR_MENU_SLUG,
					'user_code' => $user_code,
					'result'    => $result_status,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handles client key revocation from the admin page.
	 *
	 * @return void
	 */
	public function handle_revoke_client_key(): void {
		if ( ! current_user_can( self::MENU_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to revoke client keys.', 'npcink-ai-client-adapter' ) );
		}

		$key_id = isset( $_POST['key_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['key_id'] ) ) : '';
		check_admin_referer( self::REVOKE_KEY_ACTION . '_' . $key_id );

		$result = ( new Controller() )->revoke_client_key_by_id( $key_id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		wp_safe_redirect( $this->connection_page_url() );
		exit;
	}

	/**
	 * Handles OpenClaw Application Password creation.
	 *
	 * @return void
	 */
	public function handle_create_openclaw_password(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to create AI client handoff credentials.', 'npcink-ai-client-adapter' ) );
		}

		check_admin_referer( self::CREATE_ACTION );

		if ( ! $this->application_password_fallback_enabled() ) {
			wp_die( esc_html__( 'Application Password fallback is disabled for this environment.', 'npcink-ai-client-adapter' ) );
		}

		$confirmed_fallback = isset( $_POST[ self::APPLICATION_PASSWORD_FALLBACK_CONFIRM_FIELD ] )
			&& '1' === sanitize_text_field( wp_unslash( (string) $_POST[ self::APPLICATION_PASSWORD_FALLBACK_CONFIRM_FIELD ] ) );
		if ( ! $confirmed_fallback ) {
			wp_die( esc_html__( 'Confirm that you understand this fallback creates a WordPress Application Password.', 'npcink-ai-client-adapter' ) );
		}

		if ( ! $this->can_create_application_password() || ! class_exists( 'WP_Application_Passwords' ) ) {
			wp_die( esc_html( $this->application_password_unavailable_message() ) );
		}

		$user_id            = get_current_user_id();
		$application_name   = isset( $_POST['application_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['application_name'] ) ) : '';
		$application_name   = '' !== $application_name ? $application_name : 'AI client via Npcink AI Client Adapter';
		$include_local_tls  = ! empty( $_POST['include_local_tls'] );
		$created            = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			array(
				'name'   => $application_name,
				'app_id' => wp_generate_uuid4(),
			)
		);

		if ( is_wp_error( $created ) ) {
			$this->render_created_handoff_error( $created );
			exit;
		}

		status_header( 200 );
		nocache_headers();
		$this->render_created_handoff( (string) $created[0], is_array( $created[1] ?? null ) ? $created[1] : array(), $include_local_tls );
		exit;
	}

	/**
	 * Returns current adapter health data.
	 *
	 * @return array<string,mixed>
	 */
	private function health(): array {
		$request  = new WP_REST_Request( 'GET', '/' . Controller::NAMESPACE . '/health' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Converts health into one status row.
	 *
	 * @param array<string,mixed> $health Health response.
	 * @return array{level:string,label:string}
	 */
	private function status( array $health ): array {
		if ( empty( $health ) ) {
			return array(
				'level' => 'error',
				'label' => __( 'Unavailable', 'npcink-ai-client-adapter' ),
			);
		}

		if ( ! empty( $health['core_capabilities'] ) && ! empty( $health['abilities_catalog'] ) && ! empty( $health['dependency_contracts_ready'] ) ) {
			return array(
				'level' => 'ok',
				'label' => __( 'Ready', 'npcink-ai-client-adapter' ),
			);
		}

		return array(
			'level' => 'warning',
			'label' => __( 'Needs dependencies', 'npcink-ai-client-adapter' ),
		);
	}

	/**
	 * Formats a stored UTC datetime for the site's WordPress timezone.
	 *
	 * @param string $datetime UTC datetime string.
	 * @return string
	 */
	private function display_datetime( string $datetime ): string {
		$datetime = trim( $datetime );
		if ( '' === $datetime ) {
			return '';
		}

		$has_timezone = (bool) preg_match( '/(?:Z|UTC|[+-]\d{2}:?\d{2})$/i', $datetime );
		$timestamp    = strtotime( $has_timezone ? $datetime : $datetime . ' UTC' );
		if ( false === $timestamp ) {
			return $datetime;
		}

		if ( function_exists( 'wp_date' ) ) {
			return wp_date( self::DATETIME_DISPLAY_FORMAT, $timestamp );
		}

		if ( function_exists( 'date_i18n' ) ) {
			return date_i18n( self::DATETIME_DISPLAY_FORMAT, $timestamp, true );
		}

		return gmdate( self::DATETIME_DISPLAY_FORMAT, $timestamp );
	}

	/**
	 * Returns a sanitized scalar request field without direct superglobal reads.
	 *
	 * @param int    $input_type One of the INPUT_* constants.
	 * @param string $key        Request field name.
	 * @return string
	 */
	private function request_text_field( int $input_type, string $key ): string {
		$value = filter_input( $input_type, $key, FILTER_UNSAFE_RAW );
		if ( null === $value || false === $value || is_array( $value ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( (string) $value ) );
	}

	/**
	 * Enqueues assets for the one-time credential handoff page.
	 *
	 * @return void
	 */
	private function enqueue_created_handoff_assets(): void {
		wp_enqueue_style(
			'npcink-openclaw-adapter-created-handoff',
			plugins_url( 'assets/created-handoff.css', NPCINK_OPENCLAW_ADAPTER_FILE ),
			array(),
			NPCINK_OPENCLAW_ADAPTER_VERSION
		);
		wp_enqueue_script(
			'npcink-openclaw-adapter-created-handoff',
			plugins_url( 'assets/created-handoff.js', NPCINK_OPENCLAW_ADAPTER_FILE ),
			array(),
			NPCINK_OPENCLAW_ADAPTER_VERSION,
			false
		);
	}

	/**
	 * Renders one-time OpenClaw credential handoff.
	 *
	 * @param string              $password Application Password.
	 * @param array<string,mixed> $item Application Password item.
	 * @param bool                $include_local_tls Whether to include local TLS hints.
	 * @return void
	 */
	private function render_created_handoff( string $password, array $item, bool $include_local_tls ): void {
		$user          = wp_get_current_user();
		$username      = $user->exists() ? (string) $user->user_login : '';
		$base_url      = rest_url( Controller::NAMESPACE );
		$password_uuid = (string) ( $item['uuid'] ?? '' );
		$this->enqueue_created_handoff_assets();
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>" />
			<meta name="viewport" content="width=device-width, initial-scale=1" />
			<title><?php echo esc_html__( 'AI Client Connection Created', 'npcink-ai-client-adapter' ); ?></title>
			<?php wp_print_styles( array( 'npcink-openclaw-adapter-created-handoff' ) ); ?>
		</head>
		<body
			data-maa-copied-label="<?php echo esc_attr__( 'Copied', 'npcink-ai-client-adapter' ); ?>"
			data-maa-copy-failed-label="<?php echo esc_attr__( 'Copy failed', 'npcink-ai-client-adapter' ); ?>"
		>
			<main>
				<h1><?php echo esc_html__( 'AI Client Connection Created', 'npcink-ai-client-adapter' ); ?></h1>
				<div class="notice">
					<p><?php echo esc_html__( 'Copy this Application Password now. WordPress shows it only once and stores only a hash.', 'npcink-ai-client-adapter' ); ?></p>
					<p><?php echo esc_html__( 'Paste it only into the AI client dedicated secret field. Adapter does not grant provider credentials, prompt management, workflow runtime, or Cloud connector authority.', 'npcink-ai-client-adapter' ); ?></p>
				</div>
				<section class="secret-panel">
					<h2><?php echo esc_html__( 'One-time Application Password', 'npcink-ai-client-adapter' ); ?></h2>
					<textarea id="maa-application-password" rows="3" readonly><?php echo esc_textarea( $password ); ?></textarea>
					<p class="inline-actions"><button type="button" class="button button-primary" data-maa-created-copy-target="maa-application-password"><?php echo esc_html__( 'Copy Application Password', 'npcink-ai-client-adapter' ); ?></button></p>
				</section>
				<table>
					<tbody>
						<tr><th scope="row"><?php echo esc_html__( 'Adapter URL', 'npcink-ai-client-adapter' ); ?></th><td><code><?php echo esc_html( $base_url ); ?></code></td></tr>
						<tr><th scope="row"><?php echo esc_html__( 'WordPress user', 'npcink-ai-client-adapter' ); ?></th><td><code><?php echo esc_html( $username ); ?></code></td></tr>
						<tr><th scope="row"><?php echo esc_html__( 'Password UUID', 'npcink-ai-client-adapter' ); ?></th><td><code><?php echo esc_html( $password_uuid ); ?></code></td></tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Connection manifest', 'npcink-ai-client-adapter' ); ?></th>
							<td><textarea id="maa-connection-manifest" rows="12" readonly><?php echo esc_textarea( $this->openclaw_connection_manifest_text( $username, $password_uuid ) ); ?></textarea><p class="inline-actions"><button type="button" class="button" data-maa-created-copy-target="maa-connection-manifest"><?php echo esc_html__( 'Copy manifest', 'npcink-ai-client-adapter' ); ?></button></p></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'AI client env placeholder', 'npcink-ai-client-adapter' ); ?></th>
							<td><textarea id="maa-created-env-placeholder" rows="5" readonly><?php echo esc_textarea( $this->openclaw_env_text( $username, $include_local_tls ) ); ?></textarea><p class="inline-actions"><button type="button" class="button" data-maa-created-copy-target="maa-created-env-placeholder"><?php echo esc_html__( 'Copy env placeholder', 'npcink-ai-client-adapter' ); ?></button></p></td>
						</tr>
					</tbody>
				</table>
				<p class="actions"><a class="button" href="<?php echo esc_url( $this->connection_page_url() ); ?>"><?php echo esc_html__( 'Back to Npcink AI Client Adapter', 'npcink-ai-client-adapter' ); ?></a></p>
			</main>
			<?php wp_print_scripts( array( 'npcink-openclaw-adapter-created-handoff' ) ); ?>
		</body>
		</html>
		<?php
	}

	/**
	 * Renders Application Password creation failure.
	 *
	 * @param WP_Error $error Error.
	 * @return void
	 */
	private function render_created_handoff_error( WP_Error $error ): void {
		$data   = $error->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 500 ) : 500;
		status_header( $status );
		wp_die( esc_html( $error->get_error_message() ) );
	}

	/**
	 * Returns whether the current user can create an Application Password.
	 *
	 * @return bool
	 */
	private function can_create_application_password(): bool {
		if ( ! $this->application_password_fallback_enabled() ) {
			return false;
		}

		if ( ! function_exists( 'wp_is_application_passwords_available_for_user' ) || ! class_exists( 'WP_Application_Passwords' ) ) {
			return false;
		}

		$user = wp_get_current_user();
		return $user->exists() && wp_is_application_passwords_available_for_user( $user );
	}

	/**
	 * Returns whether the Application Password fallback is enabled.
	 *
	 * @return bool
	 */
	private function application_password_fallback_enabled(): bool {
		return ! defined( 'NPCINK_OPENCLAW_ADAPTER_DISABLE_APPLICATION_PASSWORD_FALLBACK' )
			|| ! (bool) constant( 'NPCINK_OPENCLAW_ADAPTER_DISABLE_APPLICATION_PASSWORD_FALLBACK' );
	}

	/**
	 * Returns the Application Password unavailable message.
	 *
	 * @return string
	 */
	private function application_password_unavailable_message(): string {
		if ( ! $this->application_password_fallback_enabled() ) {
			return __( 'Application Password fallback is disabled for this environment.', 'npcink-ai-client-adapter' );
		}

		return __( 'Application Passwords are not available for this user or site.', 'npcink-ai-client-adapter' );
	}

	/**
	 * Builds OpenClaw env text.
	 *
	 * @param string $username WordPress username.
	 * @param bool   $include_local_tls Whether to include local TLS hints.
	 * @return string
	 */
	private function openclaw_env_text( string $username, bool $include_local_tls ): string {
		$lines = array(
			'NPCINK_OPENCLAW_ADAPTER_BASE_URL=' . rest_url( Controller::NAMESPACE ),
			'NPCINK_OPENCLAW_ADAPTER_USERNAME=' . $username,
			'NPCINK_OPENCLAW_ADAPTER_APPLICATION_PASSWORD=<store-in-openclaw-secret-vault>',
		);

		if ( $include_local_tls ) {
			$lines[] = 'NPCINK_OPENCLAW_ADAPTER_INSECURE_SSL=true';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Builds the non-secret OpenClaw connection manifest.
	 *
	 * @param string $username WordPress username.
	 * @param string $password_uuid Application Password UUID.
	 * @return string
	 */
	private function openclaw_connection_manifest_text( string $username, string $password_uuid ): string {
		$manifest = array(
			'connection_id'    => 'local-wordpress',
			'adapter_base_url' => rest_url( Controller::NAMESPACE ),
			'username'         => $username,
			'auth'             => array(
				'type'          => 'wordpress_application_password',
				'password_uuid' => $password_uuid,
			),
			'urls'             => array(
				'health'       => rest_url( Controller::NAMESPACE . '/health' ),
				'help'         => rest_url( Controller::NAMESPACE . '/help' ),
				'capabilities' => rest_url( Controller::NAMESPACE . '/capabilities' ),
			),
				'note'             => 'Secret must be stored through the AI client credential store or dedicated secret field, not chat, tools, files, logs, proposal payloads, or copied handoff text.',
		);

		$json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * Counts active key-pair client records.
	 *
	 * @param array<int,array<string,mixed>> $key_records Key records.
	 * @return int
	 */
	private function active_key_pair_count( array $key_records ): int {
		$active = 0;

		foreach ( $key_records as $record ) {
			if ( '' === (string) ( $record['revoked_at'] ?? '' ) ) {
				$active++;
			}
		}

		return $active;
	}

	/**
	 * Builds a compact key-pair summary for the default connection panel.
	 *
	 * @param array<int,array<string,mixed>> $key_records Key records.
	 * @return string
	 */
	private function key_pair_summary_text( array $key_records ): string {
		$active = 0;
		$latest = '';

		foreach ( $key_records as $record ) {
			if ( '' !== (string) ( $record['revoked_at'] ?? '' ) ) {
				continue;
			}

			$active++;
			$last_used_at = (string) ( $record['last_used_at'] ?? '' );
			if ( '' !== $last_used_at && ( '' === $latest || strtotime( $last_used_at ) > strtotime( $latest ) ) ) {
				$latest = $last_used_at;
			}
		}

		if ( 0 === $active ) {
			return __( 'No active key-pair clients', 'npcink-ai-client-adapter' );
		}

		if ( '' !== $latest ) {
			return sprintf(
				/* translators: 1: number of active clients, 2: formatted last-used timestamp. */
				_n( '%1$d active, last used %2$s', '%1$d active, last used %2$s', $active, 'npcink-ai-client-adapter' ),
				$active,
				$this->display_datetime( $latest )
			);
		}

		return sprintf(
			/* translators: %d: number of active clients. */
			_n( '%d active, not used yet', '%d active, not used yet', $active, 'npcink-ai-client-adapter' ),
			$active
		);
	}

	/**
	 * Renders registered local key-pair clients.
	 *
	 * @param array<int,array<string,mixed>> $key_records Key records.
	 * @return void
	 */
	private function render_key_pair_clients_table( array $key_records ): void {
		$active_records  = array();
		$revoked_records = array();

		foreach ( $key_records as $record ) {
			if ( '' === (string) ( $record['revoked_at'] ?? '' ) ) {
				$active_records[] = $record;
				continue;
			}

			$revoked_records[] = $record;
		}

		if ( empty( $active_records ) ) :
			?>
			<p class="description"><?php echo esc_html__( 'No active key-pair clients are registered for this administrator. Run the connect command, approve the browser prompt, then refresh this page.', 'npcink-ai-client-adapter' ); ?></p>
			<?php
		else :
			$this->render_key_pair_clients_table_rows( $active_records, true );
		endif;

		if ( ! empty( $revoked_records ) ) :
			?>
			<details class="maa-inline-disclosure maa-revoked-devices">
				<summary>
					<span class="maa-disclosure-copy">
						<strong><?php echo esc_html__( 'Revoked devices', 'npcink-ai-client-adapter' ); ?></strong>
						<span class="description">
							<?php
							printf(
								/* translators: %d: number of revoked key-pair clients. */
								esc_html( _n( '%d revoked device record', '%d revoked device records', count( $revoked_records ), 'npcink-ai-client-adapter' ) ),
								absint( count( $revoked_records ) )
							);
							?>
						</span>
					</span>
					<span class="maa-disclosure-icon" aria-hidden="true"></span>
				</summary>
				<?php $this->render_key_pair_clients_table_rows( $revoked_records, false ); ?>
			</details>
			<?php
		endif;
	}

	/**
	 * Renders a key-pair client table for active or revoked records.
	 *
	 * @param array<int,array<string,mixed>> $records Key records.
	 * @param bool                          $include_actions Whether to render revoke actions.
	 * @return void
	 */
	private function render_key_pair_clients_table_rows( array $records, bool $include_actions ): void {
		?>
		<table class="widefat striped maa-device-table">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Device', 'npcink-ai-client-adapter' ); ?></th>
					<th><?php echo esc_html__( 'Device ID', 'npcink-ai-client-adapter' ); ?></th>
					<th><?php echo esc_html__( 'Last used', 'npcink-ai-client-adapter' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'npcink-ai-client-adapter' ); ?></th>
					<?php if ( $include_actions ) : ?>
						<th><?php echo esc_html__( 'Action', 'npcink-ai-client-adapter' ); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $records as $record ) : ?>
					<?php
					$admin_label = (string) ( $record['admin_label'] ?? '' );
					$client_name = (string) ( $record['client_name'] ?? '' );
					$device_name = (string) ( $record['device_name'] ?? '' );
					$fingerprint = (string) ( $record['fingerprint'] ?? '' );
					$fingerprint = strlen( $fingerprint ) > 12 ? substr( $fingerprint, -12 ) : $fingerprint;
					$is_active   = '' === (string) ( $record['revoked_at'] ?? '' );
					?>
					<tr>
						<td>
							<?php if ( '' !== $admin_label ) : ?>
								<strong><?php echo esc_html( $admin_label ); ?></strong><br>
								<span class="description"><?php echo esc_html( trim( $client_name . ' / ' . $device_name, ' /' ) ); ?></span>
							<?php else : ?>
								<?php echo esc_html( '' !== $client_name ? $client_name : $device_name ); ?>
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $fingerprint ); ?></code></td>
						<td><?php echo esc_html( $this->display_datetime( (string) ( $record['last_used_at'] ?? '' ) ) ); ?></td>
						<td>
							<span class="maa-device-status maa-device-status-<?php echo $is_active ? 'active' : 'revoked'; ?>">
								<?php echo $is_active ? esc_html__( 'Active', 'npcink-ai-client-adapter' ) : esc_html__( 'Revoked', 'npcink-ai-client-adapter' ); ?>
							</span>
						</td>
						<?php if ( $include_actions ) : ?>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( 'Revoke this device? It must pair again before it can connect.', 'npcink-ai-client-adapter' ) ); ?>');">
									<input type="hidden" name="action" value="<?php echo esc_attr( self::REVOKE_KEY_ACTION ); ?>" />
									<input type="hidden" name="key_id" value="<?php echo esc_attr( (string) ( $record['key_id'] ?? '' ) ); ?>" />
									<?php wp_nonce_field( self::REVOKE_KEY_ACTION . '_' . (string) ( $record['key_id'] ?? '' ) ); ?>
									<button type="submit" class="button-link-delete maa-revoke-button"><?php echo esc_html__( 'Revoke authorization', 'npcink-ai-client-adapter' ); ?></button>
								</form>
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Builds the local CLI connect command.
	 *
	 * @param bool $include_local_tls Whether to include the local TLS flag.
	 * @return string
	 */
	private function local_cli_connect_command( bool $include_local_tls ): string {
		return $this->local_cli_prefix() . ' connect --site=' . home_url() . ' --profile=local' . $this->local_cli_tls_flag( $include_local_tls );
	}

	/**
	 * Builds the local CLI executable prefix.
	 *
	 * @return string
	 */
	private function local_cli_prefix(): string {
		return 'cd ~ && npm exec --yes --package ' . escapeshellarg( self::LOCAL_CLI_PACKAGE ) . ' -- npcink-openclaw-adapter';
	}

	/**
	 * Builds the local TLS flag for LocalWP URLs.
	 *
	 * @param bool $include_local_tls Whether to include the local TLS flag.
	 * @return string
	 */
	private function local_cli_tls_flag( bool $include_local_tls ): string {
		return $include_local_tls ? ' --insecure-local-tls' : '';
	}

	/**
	 * Returns whether a URL points at a local testing host.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_local_url( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return false;
		}

		$host = strtolower( trim( $host, '[]' ) );
		return 'localhost' === $host || '127.0.0.1' === $host || '::1' === $host || substr( $host, -6 ) === '.local';
	}
}
