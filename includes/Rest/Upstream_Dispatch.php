<?php
/**
 * Upstream Core/Toolkit REST transport domain service.
 *
 * Owns internal upstream dispatch (token attachment, current-user
 * save/restore, response and error mapping), the Core app-token source,
 * sensitive-response sanitization, live route availability, and the
 * request-local Core capability discovery cache. Route handlers and
 * request-scoped state stay in the Controller; dependency gating and
 * operation events arrive as injected callables.
 *
 * @package NpcinkOpenClawAdapter
 */

namespace Npcink\OpenClawAdapter\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends internal upstream REST requests and maps their responses.
 */
final class Upstream_Dispatch {

	/**
	 * Core capabilities discovery cache TTL, seconds.
	 */
	const DISCOVERY_CACHE_TTL = 60;
	/**
	 * Cap on upstream error detail retained for operator diagnostics, bytes.
	 */
	const MAX_UPSTREAM_ERROR_DETAIL_BYTES = 8192;

	/**
	 * Ed25519 signing auth service for bounded text fields.
	 *
	 * @var Signing_Auth
	 */
	private $signing_auth;

	/**
	 * Emits operation events through the Controller-owned observability path.
	 *
	 * @var callable
	 */
	private $emit_event;

	/**
	 * Returns the missing-dependency error for a route, or null when ready.
	 *
	 * @var callable
	 */
	private $missing_dependency_for_route;

	/**
	 * Returns the request-scoped signed client fingerprint.
	 *
	 * @var callable
	 */
	private $signed_client_fingerprint_provider;

	/**
	 * Request-local Core capability discovery cache.
	 *
	 * @var array<string,mixed>|null
	 */
	private $core_capabilities_cache = null;

	/**
	 * Creates the upstream dispatch service.
	 *
	 * @param Signing_Auth $signing_auth Signing auth service.
	 * @param callable     $emit_event Operation event emitter: ( kind, started, error, context ).
	 * @param callable     $missing_dependency_for_route Dependency gate: ( route ) -> WP_Error|null.
	 */
	public function __construct( Signing_Auth $signing_auth, callable $emit_event, callable $missing_dependency_for_route, callable $signed_client_fingerprint_provider ) {
		$this->signing_auth                       = $signing_auth;
		$this->emit_event                         = $emit_event;
		$this->missing_dependency_for_route       = $missing_dependency_for_route;
		$this->signed_client_fingerprint_provider = $signed_client_fingerprint_provider;
	}

	/**
	 * Sends one internal upstream REST request to Core or the Abilities API.
	 *
	 * Transport only: token attachment, current-user save/restore around Core
	 * app-token requests, response/error mapping, and operation events through
	 * the injected emitter. The Controller wrapper supplies the request-scoped
	 * signed client fingerprint and keeps the dependency gate callable.
	 *
	 * @param string $method HTTP method.
	 * @param string $route REST route.
	 * @param array<string,mixed> $params Params.
	 * @param bool   $query_params Whether params should be query params.
	 * @param bool   $json_body Whether params should be encoded as JSON body.
	 * @param bool   $use_core_app_token Whether to attach the Core app token.
	 * @param string $signed_client_fingerprint Request-scoped signed client fingerprint.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send( string $method, string $route, array $params = array(), bool $query_params = false, bool $json_body = false, bool $use_core_app_token = true, string $signed_client_fingerprint = '' ) {
		$started          = microtime( true );
		$dependency_error = call_user_func( $this->missing_dependency_for_route, $route );
		if ( is_wp_error( $dependency_error ) ) {
			call_user_func(
				$this->emit_event,
				'adapter.core.request',
				$started,
				$dependency_error,
				array(
					'method'      => strtoupper( $method ),
					'route'       => $route,
					'status_code' => absint( is_array( $dependency_error->get_error_data() ) ? ( $dependency_error->get_error_data()['status'] ?? 503 ) : 503 ),
				)
			);

			return $dependency_error;
		}

		$request = new WP_REST_Request( $method, $route );
		$token   = $use_core_app_token ? $this->core_app_token() : '';
		$user_id = get_current_user_id();

		if ( '' !== $token && 0 === strpos( $route, '/npcink-governance-core/v1/' ) ) {
			$request->set_header( 'x-npcink-governance-core-app-token', $token );
			$fingerprint = $signed_client_fingerprint;
			if ( '' !== $fingerprint ) {
				$request->set_header( 'x-npcink-adapter-signed-client-fingerprint', $fingerprint );
				$request->set_header( 'x-npcink-adapter-client-key-fingerprint', $fingerprint );
			}
			wp_set_current_user( 0 );
		}

		if ( $query_params ) {
			$request->set_query_params( $params );
		} elseif ( $json_body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		} else {
			foreach ( $params as $key => $value ) {
				$request->set_param( $key, $value );
			}
		}

		try {
			$response = rest_do_request( $request );
		} finally {
			if ( '' !== $token && 0 === strpos( $route, '/npcink-governance-core/v1/' ) ) {
				wp_set_current_user( $user_id );
			}
		}
		$status = (int) $response->get_status();

		if ( $status < 200 || $status >= 300 ) {
			$data    = $response->get_data();
			$code    = is_array( $data ) ? (string) ( $data['code'] ?? 'npcink_openclaw_adapter_upstream_failed' ) : 'npcink_openclaw_adapter_upstream_failed';
			$message = is_array( $data ) ? (string) ( $data['message'] ?? __( 'The upstream WordPress REST request failed.', 'npcink-ai-client-adapter' ) ) : __( 'The upstream WordPress REST request failed.', 'npcink-ai-client-adapter' );

			if ( 'npcink_governance_core_app_auth_expired' === $code ) {
				$message .= ' ' . __( 'This Core app token has expired: ask the WordPress administrator to rotate it, then update NPCINK_OPENCLAW_ADAPTER_CORE_APP_TOKEN.', 'npcink-ai-client-adapter' );
			}

			$error_data = array(
				'status'         => $status,
				'upstream_route' => $route,
				'upstream_data'  => $this->public_upstream_error_data( $data ),
			);

			// Relay Core 429 retry guidance under the adapter's retry_after key
			// so existing Retry-After helpers apply to relayed Core errors too.
			// Core signals backoff in the error body (data.retry_after_seconds)
			// and as a standard Retry-After response header; honor both.
			if ( 429 === $status ) {
				$retry_after = 0;
				if ( is_array( $data ) && is_array( $data['data'] ?? null ) ) {
					$retry_after = absint( $data['data']['retry_after_seconds'] ?? 0 );
				}
				if ( $retry_after < 1 && method_exists( $response, 'get_headers' ) ) {
					$headers = $response->get_headers();
					if ( is_array( $headers ) ) {
						$retry_after = absint( $headers['Retry-After'] ?? $headers['retry-after'] ?? 0 );
					}
				}
				if ( $retry_after > 0 ) {
					$error_data['retry_after'] = $retry_after;
				}
			}

			call_user_func(
				$this->emit_event,
				'adapter.core.request',
				$started,
				new WP_Error( $code, $message, array( 'status' => $status ) ),
				array(
					'method'      => strtoupper( $method ),
					'route'       => $route,
					'status_code' => $status,
				)
			);

			return new WP_Error( $code, $message, $error_data );
		}

		call_user_func(
			$this->emit_event,
			'adapter.core.request',
			$started,
			null,
			array(
				'method'      => strtoupper( $method ),
				'route'       => $route,
				'status_code' => $status,
			)
		);

		return new WP_REST_Response( $response->get_data(), $status );
	}
	/**
	 * Returns the configured Core app token source without exposing the token.
	 *
	 * @return string constant|environment|none
	 */
	public function core_app_token_source(): string {
		if ( defined( 'NPCINK_OPENCLAW_ADAPTER_CORE_APP_TOKEN' ) && '' !== trim( (string) constant( 'NPCINK_OPENCLAW_ADAPTER_CORE_APP_TOKEN' ) ) ) {
			return 'constant';
		}

		$env_token = getenv( 'NPCINK_OPENCLAW_ADAPTER_CORE_APP_TOKEN' );
		if ( is_string( $env_token ) && '' !== trim( $env_token ) ) {
			return 'environment';
		}

		return 'none';
	}
	/**
	 * Returns the configured Core app token without exposing it in responses.
	 *
	 * @return string
	 */
	public function core_app_token(): string {
		$source = $this->core_app_token_source();
		if ( 'constant' === $source ) {
			return trim( (string) constant( 'NPCINK_OPENCLAW_ADAPTER_CORE_APP_TOKEN' ) );
		}

		if ( 'environment' === $source ) {
			$env_token = getenv( 'NPCINK_OPENCLAW_ADAPTER_CORE_APP_TOKEN' );
			return trim( (string) $env_token );
		}

		return '';
	}
	/**
	 * Returns a bounded, redacted upstream error summary for public Adapter errors.
	 *
	 * @param mixed $data Upstream response data.
	 * @return array<string,mixed>
	 */
	public function public_upstream_error_data( $data ): array {
		$summary = $this->sanitize_public_response_value( $data, 0 );
		if ( ! is_array( $summary ) ) {
			$summary = array( 'message' => (string) $summary );
		}

		$encoded = wp_json_encode( $summary );
		if ( is_string( $encoded ) && strlen( $encoded ) > self::MAX_UPSTREAM_ERROR_DETAIL_BYTES ) {
			return array(
				'truncated' => true,
				'bytes'     => strlen( $encoded ),
				'code'      => sanitize_key( (string) ( $summary['code'] ?? '' ) ),
				'message'   => sanitize_text_field( (string) ( $summary['message'] ?? __( 'The upstream WordPress REST request failed.', 'npcink-ai-client-adapter' ) ) ),
			);
		}

		return $summary;
	}
	/**
	 * Sanitizes a value for bounded public diagnostics.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Current depth.
	 * @return mixed
	 */
	public function sanitize_public_response_value( $value, int $depth ) {
		if ( $depth > 4 ) {
			return '[truncated]';
		}

		if ( is_scalar( $value ) || null === $value ) {
			if ( is_string( $value ) ) {
				return $this->signing_auth->bounded_text_field( $value, 500 );
			}
			return $value;
		}

		if ( ! is_array( $value ) ) {
			return '[unsupported]';
		}

		$output = array();
		$index  = 0;
		foreach ( $value as $key => $child ) {
			if ( $index >= 50 ) {
				$output['truncated'] = true;
				break;
			}
			$key_text = is_string( $key ) ? sanitize_key( $key ) : $key;
			if ( is_string( $key ) && $this->is_sensitive_public_response_key( $key ) ) {
				$output[ $key_text ] = '[redacted]';
			} else {
				$output[ $key_text ] = $this->sanitize_public_response_value( $child, $depth + 1 );
			}
			++$index;
		}

		return $output;
	}
	/**
	 * Returns whether a public diagnostic key should be redacted.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public function is_sensitive_public_response_key( string $key ): bool {
		$key = strtolower( $key );
		foreach ( array( 'password', 'token', 'secret', 'credential', 'authorization', 'cookie', 'nonce', 'signature', 'private_key', 'prompt', 'content', 'html', 'blocks' ) as $needle ) {
			if ( false !== strpos( $key, $needle ) ) {
				return true;
			}
		}

		return false;
	}
	/**
	 * Returns whether a REST route is registered.
	 *
	 * @param string $route REST route.
	 * @return bool
	 */
	public function rest_route_available( string $route ): bool {
		$routes = rest_get_server()->get_routes();
		return isset( $routes[ $route ] );
	}
	/**
	 * Returns Core capability discovery with request-local and short transient caching.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function core_capabilities_data() {
		if ( is_array( $this->core_capabilities_cache ) ) {
			return $this->core_capabilities_cache;
		}

		$cached = get_transient( 'npcink_openclaw_adapter_core_capabilities_v1' );
		if ( is_array( $cached ) ) {
			$this->core_capabilities_cache = $cached;
			return $cached;
		}

		$response = $this->send( 'GET', '/npcink-governance-core/v1/capabilities', array(), false, false, true, (string) call_user_func( $this->signed_client_fingerprint_provider ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'npcink_openclaw_adapter_invalid_core_capabilities',
				__( 'Core capabilities response is invalid.', 'npcink-ai-client-adapter' ),
				array( 'status' => 502 )
			);
		}

		$this->core_capabilities_cache = $data;
		set_transient( 'npcink_openclaw_adapter_core_capabilities_v1', $data, self::DISCOVERY_CACHE_TTL );

		return $data;
	}
	/**
	 * Primes the request-local Core capability cache from a fresh response.
	 *
	 * @param array<string,mixed> $data Capabilities data.
	 * @return void
	 */
	public function prime_capabilities( array $data ): void {
		$this->core_capabilities_cache = $data;
		set_transient( 'npcink_openclaw_adapter_core_capabilities_v1', $data, self::DISCOVERY_CACHE_TTL );
	}

	/**
	 * Finds one capability row from Core.
	 *
	 * @param string $ability_id Ability id.
	 * @return array<string,mixed>|WP_Error
	 */
	public function find_core_capability( string $ability_id ) {
		$data = $this->core_capabilities_data();
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		foreach ( (array) ( is_array( $data ) ? ( $data['items'] ?? array() ) : array() ) as $item ) {
			if ( is_array( $item ) && (string) ( $item['ability_id'] ?? '' ) === $ability_id ) {
				return $item;
			}
		}

		return new WP_Error(
			'npcink_openclaw_adapter_ability_not_found',
			__( 'The requested ability is not discoverable through Core.', 'npcink-ai-client-adapter' ),
			array( 'status' => 404 )
		);
	}
}
