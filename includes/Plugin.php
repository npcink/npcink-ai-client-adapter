<?php
/**
 * Plugin bootstrap.
 *
 * @package NpcinkOpenClawAdapter
 */

namespace Npcink\OpenClawAdapter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers plugin hooks.
 */
final class Plugin {
	/**
	 * REST dispatch start timestamps keyed by request object.
	 *
	 * @var array<string,float>
	 */
	private $dispatch_starts = array();

	/**
	 * Boots the plugin.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'load_bundled_translations' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_filter( 'rest_request_before_callbacks', array( $this, 'capture_adapter_dispatch_start' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'emit_adapter_dispatch_event' ), 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( NPCINK_OPENCLAW_ADAPTER_FILE ), array( $this, 'filter_plugin_action_links' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_page' ), 20 );
		add_action( 'admin_notices', array( $this, 'render_dependency_notice' ) );
		add_action( 'admin_post_npcink_openclaw_adapter_create_openclaw_password', array( $this, 'handle_create_openclaw_password' ) );
		add_action( 'admin_post_npcink_openclaw_adapter_pairing_decision', array( $this, 'handle_pairing_decision' ) );
		add_action( 'admin_post_npcink_openclaw_adapter_revoke_client_key', array( $this, 'handle_revoke_client_key' ) );
	}

	/**
	 * Adds a settings shortcut on the WordPress plugins screen.
	 *
	 * @param array<int|string,string> $links Existing plugin action links.
	 * @return array<int|string,string>
	 */
	public function filter_plugin_action_links( array $links ): array {
		$settings_url = menu_page_url( 'npcink-ai-client-adapter', false );
		if ( '' === $settings_url ) {
			$settings_url = admin_url( 'options-general.php?page=npcink-ai-client-adapter' );
		}

		array_unshift(
			$links,
			sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $settings_url ),
				esc_html__( 'Settings', 'npcink-ai-client-adapter' )
			)
		);

		return $links;
	}

	/**
	 * Loads bundled translations for local/private installs.
	 *
	 * @return void
	 */
	public function load_bundled_translations(): void {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$locale = sanitize_file_name( (string) $locale );
		if ( '' === $locale ) {
			return;
		}

		$language_file = NPCINK_OPENCLAW_ADAPTER_DIR . 'languages/npcink-ai-client-adapter-' . $locale . '.mo';
		if ( is_readable( $language_file ) ) {
			load_textdomain( 'npcink-ai-client-adapter', $language_file );
		}
	}

	/**
	 * Captures the start time for an Adapter REST dispatch.
	 *
	 * @param mixed           $result Pre-dispatch result.
	 * @param \WP_REST_Server $server REST server.
	 * @param \WP_REST_Request $request REST request.
	 * @return mixed
	 */
	public function capture_adapter_dispatch_start( $result, $server, $request ) {
		if ( ! $request instanceof \WP_REST_Request || ! $this->is_adapter_dispatch_request( $request ) ) {
			return $result;
		}

		$this->dispatch_starts[ spl_object_hash( $request ) ] = microtime( true );

		return $result;
	}

	/**
	 * Emits a metadata-only OpenClaw dispatch event for Adapter REST requests.
	 *
	 * @param mixed            $response REST response.
	 * @param \WP_REST_Server  $server REST server.
	 * @param \WP_REST_Request $request REST request.
	 * @return mixed
	 */
	public function emit_adapter_dispatch_event( $response, $server, $request ) {
		if ( ! $request instanceof \WP_REST_Request || ! $this->is_adapter_dispatch_request( $request ) ) {
			return $response;
		}

		$key     = spl_object_hash( $request );
		$started = isset( $this->dispatch_starts[ $key ] ) ? (float) $this->dispatch_starts[ $key ] : microtime( true );
		unset( $this->dispatch_starts[ $key ] );

		$status_code = $this->adapter_dispatch_status_code( $response );
		$is_error    = $status_code >= 400;
		$payload     = array_merge(
			array(
				'status'      => $is_error ? 'error' : 'ok',
				'method'      => strtoupper( sanitize_key( $request->get_method() ) ),
				'route'       => sanitize_text_field( $request->get_route() ),
				'status_code' => $status_code,
				'latency_ms'  => max( 0, (int) round( ( microtime( true ) - $started ) * 1000 ) ),
			),
			$this->adapter_dispatch_request_context( $request )
		);

		$event_kind = $is_error ? 'adapter.openclaw.dispatch.failed' : 'adapter.openclaw.dispatch.completed';
		if ( $is_error ) {
			$payload['error_code']    = $this->adapter_dispatch_error_code( $response );
			$payload['status_detail'] = 'http_' . $status_code;
			$this->record_recent_client_error( $payload );
		}
		$payload['event_id'] = $this->adapter_dispatch_event_id( $event_kind, $payload );

		Observability::emit( $event_kind, $payload );

		return $response;
	}

	/**
	 * Records one bounded Adapter channel failure for the admin surface.
	 *
	 * The buffer is diagnostics-only, non-authoritative, and never replaces
	 * Core governance audit or AI Request Logs; it answers the site owner's
	 * "why did my client stop working" question without leaving wp-admin.
	 *
	 * A client stuck in a retry loop must not turn into one option write per
	 * request: a failure identical to any of the newest records (same method,
	 * route, status, and code, within 60 seconds) is skipped. The window scan
	 * covers the last five records so a client alternating between failing
	 * routes still throttles. A global cap adds a second layer: once five
	 * records land within 60 seconds, further failures in that window are
	 * skipped, so even an anonymous caller cycling distinct route tuples
	 * cannot amplify option writes. Concurrent failures may race the
	 * read-modify-write cycle and lose records; losing diagnostics rows is
	 * accepted, they are advisory only.
	 *
	 * @param array<string,mixed> $payload Sanitized dispatch payload.
	 * @return void
	 */
	private function record_recent_client_error( array $payload ): void {
		// Length caps keep one attacker-controlled unmatched route from
		// filling the bounded buffer with megabytes of junk.
		$record = array(
			'time'   => gmdate( 'c' ),
			'method' => mb_substr( (string) ( $payload['method'] ?? '' ), 0, 16 ),
			'route'  => mb_substr( (string) ( $payload['route'] ?? '' ), 0, 200 ),
			'status' => absint( $payload['status_code'] ?? 0 ),
			'code'   => mb_substr( (string) ( $payload['error_code'] ?? '' ), 0, 100 ),
		);

		$errors = get_option( 'npcink_openclaw_adapter_recent_client_errors', array() );
		if ( ! is_array( $errors ) ) {
			$errors = array();
		}
		$errors = array_values( $errors );

		$recent_window = 0;
		foreach ( array_slice( $errors, -5 ) as $recent ) {
			if ( ! is_array( $recent ) ) {
				continue;
			}

			$recent_time = strtotime( (string) ( $recent['time'] ?? '' ) );
			if ( false === $recent_time || ( time() - $recent_time ) >= 60 ) {
				continue;
			}

			++$recent_window;

			if (
				(string) ( $recent['method'] ?? '' ) === $record['method']
				&& (string) ( $recent['route'] ?? '' ) === $record['route']
				&& absint( $recent['status'] ?? 0 ) === $record['status']
				&& (string) ( $recent['code'] ?? '' ) === $record['code']
			) {
				return;
			}
		}

		// Global window cap: at most five recorded failures per minute, so
		// even a caller cycling distinct route tuples cannot amplify writes.
		if ( $recent_window >= 5 ) {
			return;
		}

		$errors[] = $record;

		update_option( 'npcink_openclaw_adapter_recent_client_errors', array_slice( $errors, -15 ), false );
	}

	/**
	 * Renders the missing-dependency notice for administrators.
	 *
	 * The notice is the activation-agnostic companion of the Adapter page's
	 * readiness checklist: without it, a site owner who activates Adapter
	 * while the suite plugins are missing sees no dashboard-level signal
	 * until they open the Adapter page. It renders only on the screens where
	 * the owner can act (plugins, dashboard, and the Adapter page), so the
	 * route-registry probe does not initialize the REST server on every
	 * admin screen.
	 *
	 * @return void
	 */
	public function render_dependency_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = is_object( $screen ) ? (string) $screen->id : '';
		if ( ! in_array( $screen_id, array( 'plugins', 'plugins-network', 'dashboard', 'dashboard-network', 'npcink-ai_page_npcink-ai-client-adapter', 'settings_page_npcink-ai-client-adapter' ), true ) ) {
			return;
		}

		$missing = Rest\Dependency_Status::missing_dependency_keys();
		if ( array() === $missing ) {
			return;
		}

		$labels = array();
		foreach ( $missing as $key ) {
			$labels[] = Rest\Dependency_Status::dependency_label( $key );
		}

		$settings_url = menu_page_url( 'npcink-ai-client-adapter', false );
		if ( '' === $settings_url ) {
			// Fallback when admin_menu has not registered the page yet: with
			// the Npcink suite the page hangs under the admin.php parent menu,
			// otherwise it lives under Settings.
			$settings_url = function_exists( 'npcink_abilities_toolkit_get_registered' )
				? admin_url( 'admin.php?page=npcink-ai-client-adapter' )
				: admin_url( 'options-general.php?page=npcink-ai-client-adapter' );
		}
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php echo esc_html__( 'Npcink AI Client Adapter cannot serve AI clients yet.', 'npcink-ai-client-adapter' ); ?></strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: comma-separated dependency labels. */
						__( 'Required suite components are missing: %s. Adapter routes fail closed until they are installed and active.', 'npcink-ai-client-adapter' ),
						implode( ', ', $labels )
					)
				);
				?>
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php echo esc_html__( 'Open the Adapter page', 'npcink-ai-client-adapter' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Checks whether a REST request targets the Adapter namespace.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool
	 */
	private function is_adapter_dispatch_request( \WP_REST_Request $request ): bool {
		return 0 === strpos( $request->get_route(), '/' . Rest\Controller::NAMESPACE . '/' );
	}

	/**
	 * Returns safe dispatch correlation fields from request params.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return array<string,string>
	 */
	private function adapter_dispatch_request_context( \WP_REST_Request $request ): array {
		$context = array();
		foreach ( array( 'adapter_request_id', 'correlation_id', 'proposal_id' ) as $key ) {
			$value = $request->get_param( $key );
			if ( is_string( $value ) && '' !== $value ) {
				$context[ $key ] = sanitize_text_field( $value );
			}
		}

		foreach ( array( 'log_context', 'caller' ) as $param ) {
			$value = $request->get_param( $param );
			if ( ! is_array( $value ) ) {
				continue;
			}

			foreach ( array( 'adapter_request_id', 'correlation_id', 'proposal_id' ) as $key ) {
				if ( isset( $context[ $key ] ) || ! isset( $value[ $key ] ) || ! is_scalar( $value[ $key ] ) ) {
					continue;
				}

				$field = sanitize_text_field( (string) $value[ $key ] );
				if ( '' !== $field ) {
					$context[ $key ] = $field;
				}
			}
		}

		return $context;
	}

	/**
	 * Extracts a stable error code from a REST response.
	 *
	 * @param mixed $response REST response.
	 * @return string
	 */
	private function adapter_dispatch_error_code( $response ): string {
		$data = is_wp_error( $response ) ? $response->get_error_data() : ( method_exists( $response, 'get_data' ) ? $response->get_data() : null );
		$code = is_array( $data ) ? sanitize_key( (string) ( $data['code'] ?? '' ) ) : '';
		if ( '' === $code && is_wp_error( $response ) ) {
			$code = sanitize_key( $response->get_error_code() );
		}

		return '' !== $code ? $code : 'adapter.dispatch_failed';
	}

	/**
	 * Extracts a REST status code from a response or error.
	 *
	 * @param mixed $response REST response.
	 * @return int
	 */
	private function adapter_dispatch_status_code( $response ): int {
		if ( is_wp_error( $response ) ) {
			$data = $response->get_error_data();
			return is_array( $data ) ? absint( $data['status'] ?? 500 ) : 500;
		}

		return method_exists( $response, 'get_status' ) ? absint( $response->get_status() ) : 0;
	}

	/**
	 * Builds a stable metadata-only event id for Adapter dispatch events.
	 *
	 * @param string              $event_kind Event kind.
	 * @param array<string,mixed> $payload Metadata-only payload.
	 * @return string
	 */
	private function adapter_dispatch_event_id( string $event_kind, array $payload ): string {
		$identity = array(
			'event_kind'         => $event_kind,
			'status'             => (string) ( $payload['status'] ?? '' ),
			'error_code'         => (string) ( $payload['error_code'] ?? '' ),
			'method'             => (string) ( $payload['method'] ?? '' ),
			'route'              => (string) ( $payload['route'] ?? '' ),
			'status_code'        => (int) ( $payload['status_code'] ?? 0 ),
			'adapter_request_id' => (string) ( $payload['adapter_request_id'] ?? '' ),
			'correlation_id'     => (string) ( $payload['correlation_id'] ?? '' ),
			'proposal_id'        => (string) ( $payload['proposal_id'] ?? '' ),
		);
		$json     = function_exists( 'wp_json_encode' ) ? wp_json_encode( $identity ) : json_encode( $identity );
		$hash     = hash( 'sha256', is_string( $json ) ? $json : '' );
		$prefix   = sanitize_key( str_replace( '.', '_', $event_kind ) );

		return $prefix . '_' . substr( $hash, 0, 32 );
	}

	/**
	 * Registers REST routes.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		$controller = new Rest\Controller();
		$controller->register_routes();
	}

	/**
	 * Registers the read-only OpenClaw connection page.
	 *
	 * @return void
	 */
	public function register_admin_page(): void {
		$page = new Admin\Connection_Page();
		$page->register();
	}

	/**
	 * Handles OpenClaw Application Password creation.
	 *
	 * @return void
	 */
	public function handle_create_openclaw_password(): void {
		$page = new Admin\Connection_Page();
		$page->handle_create_openclaw_password();
	}

	/**
	 * Handles device pairing approval or rejection.
	 *
	 * @return void
	 */
	public function handle_pairing_decision(): void {
		$page = new Admin\Connection_Page();
		$page->handle_pairing_decision();
	}

	/**
	 * Handles client key revocation.
	 *
	 * @return void
	 */
	public function handle_revoke_client_key(): void {
		$page = new Admin\Connection_Page();
		$page->handle_revoke_client_key();
	}
}
