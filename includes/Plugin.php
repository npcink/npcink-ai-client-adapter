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
		}
		$payload['event_id'] = $this->adapter_dispatch_event_id( $event_kind, $payload );

		Observability::emit( $event_kind, $payload );

		return $response;
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
