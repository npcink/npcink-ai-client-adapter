<?php
/**
 * Ed25519 signed-request authentication and device-pairing domain service.
 *
 * Owns credential parsing, canonical-string construction, nonce replay
 * protection, device pairing storage, client-key records, scope checks, and
 * pairing rate limits. The Controller remains the route/state owner: verify()
 * returns an authenticated identity instead of mutating request state, and
 * operation events flow back through the injected emitter.
 *
 * @package NpcinkOpenClawAdapter
 */

namespace Npcink\OpenClawAdapter\Rest;

use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signs, verifies, stores, and rate-limits local client credentials.
 */
final class Signing_Auth {

	/**
	 * Emits operation events through the Controller-owned observability path.
	 *
	 * @var callable
	 */
	private $emit;

	/**
	 * Creates the signing auth service.
	 *
	 * @param callable $emit_event Operation event emitter: ( kind, started, error, context ).
	 */
	public function __construct( callable $emit_event ) {
		$this->emit = $emit_event;
	}

	/**
	 * Emits one operation event through the injected emitter.
	 *
	 * @param string       $event_kind Event kind.
	 * @param float        $started Started timestamp.
	 * @param mixed        $error Error or null.
	 * @param array<mixed> $context Event context.
	 * @return void
	 */
	private function emit( string $event_kind, float $started, $error, array $context = array() ): void {
		call_user_func( $this->emit, $event_kind, $started, $error, $context );
	}

	/**
	 * Builds one structured signed-request failure with a stable reason key
	 * and an operator-facing next step.
	 *
	 * @param string $code    Stable error code.
	 * @param string $message Human-readable message.
	 * @param string $reason  Stable reason key.
	 * @param string $next_step Operator-facing next step.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	public function signed_request_error( string $code, string $message, string $reason, string $next_step, int $status ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array(
				'status'    => $status,
				'reason'    => $reason,
				'next_step' => $next_step,
			)
		);
	}
	/**
	 * Builds the structured error for requests without usable credentials.
	 *
	 * @return WP_Error
	 */
	public function authentication_required_error(): WP_Error {
		return new WP_Error(
			'npcink_openclaw_adapter_authentication_required',
			__( 'Adapter requires a paired signed client or a WordPress administrator session. Start key-pair device pairing, then send the signed request headers.', 'npcink-ai-client-adapter' ),
			array(
				'status'        => 401,
				'reason'        => 'credentials_missing',
				'auth_modes'    => array(
					'ed25519_key_pair_device_pairing',
					'wordpress_application_password',
				),
				'pairing_route' => 'POST /' . Controller::NAMESPACE . '/connect/device/start',
				'contract_doc'  => 'docs/keypair-device-pairing-contract.md',
			)
		);
	}
	/**
	 * Returns whether the request carries any Npcink signature credentials.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function carries_credentials( WP_REST_Request $request ): bool {
		$credentials = $this->signed_request_credentials( $request );

		return '' !== $credentials['key_id'] || '' !== $credentials['signature'];
	}
	/**
	 * Returns request signature credentials from X-Npcink headers or Authorization.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,string>
	 */
	public function signed_request_credentials( WP_REST_Request $request ): array {
		$credentials = array(
			'key_id'         => sanitize_text_field( (string) $request->get_header( 'x_npcink_key_id' ) ),
			'timestamp'      => sanitize_text_field( (string) $request->get_header( 'x_npcink_timestamp' ) ),
			'nonce'          => sanitize_text_field( (string) $request->get_header( 'x_npcink_nonce' ) ),
			'content_sha256' => sanitize_text_field( (string) $request->get_header( 'x_npcink_content_sha256' ) ),
			'signature_alg'  => sanitize_text_field( (string) $request->get_header( 'x_npcink_signature_alg' ) ),
			'signature'      => sanitize_text_field( (string) $request->get_header( 'x_npcink_signature' ) ),
		);

		if ( '' !== $credentials['key_id'] && '' !== $credentials['signature'] ) {
			return $credentials;
		}

		$authorization = (string) $request->get_header( 'authorization' );
		if ( ! preg_match( '/^Npcink-Signature\s+(.+)$/i', $authorization, $matches ) ) {
			return $credentials;
		}

		$parts = array();
		foreach ( explode( ',', $matches[1] ) as $piece ) {
			$pair = explode( '=', trim( $piece ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			$parts[ strtolower( trim( $pair[0] ) ) ] = trim( trim( $pair[1] ), '"' );
		}

		return array(
			'key_id'         => sanitize_text_field( (string) ( $parts['key_id'] ?? '' ) ),
			'timestamp'      => sanitize_text_field( (string) ( $parts['timestamp'] ?? '' ) ),
			'nonce'          => sanitize_text_field( (string) ( $parts['nonce'] ?? '' ) ),
			'content_sha256' => sanitize_text_field( (string) ( $parts['content_sha256'] ?? '' ) ),
			'signature_alg'  => sanitize_text_field( (string) ( $parts['alg'] ?? '' ) ),
			'signature'      => sanitize_text_field( (string) ( $parts['signature'] ?? '' ) ),
		);
	}
	/**
	 * Returns the canonical string signed by local clients.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $timestamp Timestamp.
	 * @param string          $nonce Nonce.
	 * @param string          $content_sha256 Body hash.
	 * @return string
	 */
	public function canonical_string( WP_REST_Request $request, string $timestamp, string $nonce, string $content_sha256 ): string {
		return implode(
			"\n",
			array(
				'NPCINK-AI-CLIENT-ADAPTER-V1',
				strtoupper( $request->get_method() ),
				$request->get_route(),
				Contract_Metadata::canonical_json( $this->raw_query_params() ),
				$timestamp,
				$nonce,
				$content_sha256,
			)
		);
	}
	/**
	 * Returns raw query parameters for signature canonicalization.
	 *
	 * The canonical query JSON must reflect the wire values the client signed.
	 * WP_REST_Request::get_query_params() is mutated by declared-argument
	 * sanitization before the permission callback runs (for example
	 * "limit=3" becomes the integer 3), so verification against it fails for
	 * any signed request with query parameters. $_GET holds the undecorated
	 * wire strings the signer canonicalized.
	 *
	 * @return array<string,mixed>
	 */
	public function raw_query_params(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the Ed25519 request signature is the authentication mechanism here; this is not a form nonce check.
		return isset( $_GET ) && is_array( $_GET ) ? wp_unslash( $_GET ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
	/**
	 * Encodes base64url.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public function base64url_encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}
	/**
	 * Decodes base64url.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public function base64url_decode( string $value ): string {
		$decoded = base64_decode( strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 ), true );
		return is_string( $decoded ) ? $decoded : '';
	}
	/**
	 * Sanitizes a signed local client fingerprint.
	 *
	 * @param string $fingerprint Fingerprint.
	 * @return string
	 */
	public function sanitize_signed_client_fingerprint( string $fingerprint ): string {
		$fingerprint = sanitize_text_field( $fingerprint );
		if ( 1 !== preg_match( '/^sha256:[a-f0-9]{64}$/', $fingerprint ) ) {
			return '';
		}

		return $fingerprint;
	}
	/**
	 * Sanitizes and bounds one plain text field.
	 *
	 * @param string $value      Raw value.
	 * @param int    $max_length Maximum character length.
	 * @return string
	 */
	public function bounded_text_field( string $value, int $max_length ): string {
		$value  = sanitize_text_field( $value );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
		if ( $max_length > 0 && $length > $max_length ) {
			$value = function_exists( 'mb_substr' )
				? mb_substr( $value, 0, $max_length )
				: substr( $value, 0, $max_length );
		}

		return $value;
	}
	/**
	 * Verifies an Ed25519-signed request against registered client keys.
	 *
	 * Pure verification and claim side effects only: the caller owns the
	 * request-scoped Controller state (current-user switch and fingerprint
	 * properties) derived from the returned identity.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{user_id:int,fingerprint:string}|WP_Error Authenticated identity or structured failure.
	 */
	public function verify( WP_REST_Request $request ) {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return new WP_Error(
				'npcink_openclaw_adapter_sodium_unavailable',
				__( 'Ed25519 signed requests require the PHP sodium extension.', 'npcink-ai-client-adapter' ),
				array( 'status' => 501 )
			);
		}

		$credentials = $this->signed_request_credentials( $request );

		$key_id         = $credentials['key_id'];
		$timestamp      = $credentials['timestamp'];
		$nonce          = $credentials['nonce'];
		$content_sha256 = $credentials['content_sha256'];
		$signature_alg  = $credentials['signature_alg'];
		$signature      = $credentials['signature'];

		if ( '' === $key_id || '' === $timestamp || '' === $nonce || '' === $content_sha256 || 'Ed25519' !== $signature_alg || '' === $signature ) {
			return $this->signed_request_error(
				'npcink_openclaw_adapter_signed_request_malformed',
				__( 'The signed request is missing required Npcink signature credential fields.', 'npcink-ai-client-adapter' ),
				'credentials_incomplete',
				__( 'Send key_id, timestamp, nonce, content_sha256, alg=Ed25519, and signature on every request. See docs/keypair-device-pairing-contract.md.', 'npcink-ai-client-adapter' ),
				401
			);
		}

		$timestamp_epoch = strtotime( $timestamp );
		if ( false === $timestamp_epoch || abs( time() - $timestamp_epoch ) > Controller::SIGNATURE_NONCE_TTL ) {
			return $this->signed_request_error(
				'npcink_openclaw_adapter_signed_request_timestamp_skew',
				__( 'The signed request timestamp is unparseable or outside the allowed freshness window.', 'npcink-ai-client-adapter' ),
				'timestamp_outside_window',
				__( 'Regenerate the timestamp from a synced clock and re-sign the request before retrying.', 'npcink-ai-client-adapter' ),
				401
			);
		}

		$expected_hash = 'sha256:' . hash( 'sha256', (string) $request->get_body() );
		if ( ! hash_equals( $expected_hash, $content_sha256 ) ) {
			return $this->signed_request_error(
				'npcink_openclaw_adapter_signed_request_content_hash_mismatch',
				__( 'The signed content hash does not match the request body received by the adapter.', 'npcink-ai-client-adapter' ),
				'content_hash_mismatch',
				__( 'Hash and sign the exact request body bytes; do not modify the body after signing.', 'npcink-ai-client-adapter' ),
				401
			);
		}

		$keys            = $this->key_records();
		$record          = is_array( $keys[ $key_id ] ?? null ) ? $keys[ $key_id ] : array();
		$user            = ! empty( $record ) ? get_userdata( (int) ( $record['user_id'] ?? 0 ) ) : false;
		$public_key      = $this->base64url_decode( (string) ( $record['public_key'] ?? '' ) );
		$signature_bytes = $this->base64url_decode( $signature );
		$canonical       = $this->canonical_string( $request, $timestamp, $nonce, $content_sha256 );
		if (
			empty( $record )
			|| '' !== (string) ( $record['revoked_at'] ?? '' )
			|| ! $user
			|| ! user_can( $user, 'manage_options' )
			|| 32 !== strlen( $public_key )
			|| 64 !== strlen( $signature_bytes )
			|| ! sodium_crypto_sign_verify_detached( $signature_bytes, $canonical, $public_key )
		) {
			return $this->signed_request_error(
				'npcink_openclaw_adapter_signed_request_rejected',
				__( 'The client key or request signature was rejected.', 'npcink-ai-client-adapter' ),
				'key_or_signature_rejected',
				__( 'Pair the client again through POST /connect/device/start, or rebuild the canonical string exactly as documented in docs/keypair-device-pairing-contract.md and re-sign the request.', 'npcink-ai-client-adapter' ),
				401
			);
		}

		if ( ! $this->key_scope_allows( $record, $request ) ) {
			return $this->signed_request_error(
				'npcink_openclaw_adapter_signed_request_scope_denied',
				__( 'The paired client key does not carry the scope required by this route.', 'npcink-ai-client-adapter' ),
				'scope_not_granted',
				__( 'Re-pair the client requesting the needed scope, or ask a WordPress administrator to approve a key with it.', 'npcink-ai-client-adapter' ),
				403
			);
		}

		if ( ! $this->claim_signature_nonce( $key_id, $nonce ) ) {
			return $this->signed_request_error(
				'npcink_openclaw_adapter_signed_request_nonce_replayed',
				__( 'The signed request nonce was already claimed.', 'npcink-ai-client-adapter' ),
				'nonce_already_claimed',
				__( 'Use a fresh nonce for every request; a retried request must be re-signed with a new nonce and timestamp.', 'npcink-ai-client-adapter' ),
				401
			);
		}
		if ( $this->should_update_key_last_used( (string) ( $record['last_used_at'] ?? '' ) ) ) {
			$record['last_used_at'] = gmdate( 'c' );
			$keys[ $key_id ]        = $record;
			update_option( Controller::CLIENT_KEYS_OPTION, $keys, false );
		}
		return array(
			'user_id'     => (int) ( $record['user_id'] ?? 0 ),
			'fingerprint' => $this->sanitize_signed_client_fingerprint( (string) ( $record['fingerprint'] ?? '' ) ),
		);
	}
	/**
	 * Atomically claims one verified signature nonce.
	 *
	 * The option name is unique in wp_options, so concurrent requests using the
	 * same nonce cannot both succeed. Expired records are reclaimed with a
	 * compare-and-delete query so an old cleanup cannot remove a newer claim.
	 *
	 * @param string $key_id Registered client key id.
	 * @param string $nonce Signed request nonce.
	 * @return bool
	 */
	public function claim_signature_nonce( string $key_id, string $nonce ): bool {
		$nonce_key  = Controller::SIGNATURE_NONCE_OPTION_PREFIX . hash( 'sha256', $key_id . '|' . $nonce );
		$expires_at = time() + Controller::SIGNATURE_NONCE_TTL;

		if ( $this->insert_signature_nonce_option( $nonce_key, $expires_at ) ) {
			$this->maybe_cleanup_expired_signature_nonces();
			return true;
		}

		$stored_expiry = $this->signature_nonce_option_expiry( $nonce_key );
		if ( null === $stored_expiry || $stored_expiry >= time() ) {
			return false;
		}

		if ( ! $this->delete_expired_signature_nonce_option( $nonce_key, $stored_expiry ) ) {
			return false;
		}

		if ( ! $this->insert_signature_nonce_option( $nonce_key, $expires_at ) ) {
			return false;
		}

		$this->maybe_cleanup_expired_signature_nonces();
		return true;
	}
	/**
	 * Inserts one nonce claim without WordPress' duplicate-update option path.
	 *
	 * WordPress 7 add_option() uses ON DUPLICATE KEY UPDATE, so it cannot be the
	 * strict insert-only primitive required for replay protection.
	 *
	 * @phpstan-impure Re-running the same insert can return a different result once
	 *                 an expired claim is reclaimed by a concurrent request.
	 * @param string $option_name Nonce option name.
	 * @param int    $expires_at Expiry epoch.
	 * @return bool
	 */
	public function insert_signature_nonce_option( string $option_name, int $expires_at ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT IGNORE is the atomic replay-protection claim primitive.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$option_name,
				maybe_serialize( $expires_at ),
				'off'
			)
		);

		if ( 1 !== (int) $inserted ) {
			return false;
		}

		return true;
	}
	/**
	 * Reads one nonce expiry without entering the shared options cache.
	 *
	 * @param string $option_name Nonce option name.
	 * @return int|null
	 */
	public function signature_nonce_option_expiry( string $option_name ): ?int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read bypasses the options cache to preserve nonce claim semantics.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option_name
			)
		);

		return is_numeric( $value ) ? (int) $value : null;
	}
	/**
	 * Deletes one expired nonce only if its stored value is unchanged.
	 *
	 * @param string    $option_name Option name.
	 * @param int|float|string $stored_expiry Expected stored expiry.
	 * @return bool
	 */
	public function delete_expired_signature_nonce_option( string $option_name, $stored_expiry ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional delete prevents removing a concurrently refreshed nonce.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$option_name,
				maybe_serialize( $stored_expiry )
			)
		);

		if ( 1 !== (int) $deleted ) {
			return false;
		}

		return true;
	}
	/**
	 * Performs server-randomized, low-frequency, bounded nonce cleanup.
	 *
	 * @return void
	 */
	public function maybe_cleanup_expired_signature_nonces(): void {
		if ( 1 !== wp_rand( 1, 64 ) ) {
			return;
		}

		global $wpdb;
		$like = $wpdb->esc_like( Controller::SIGNATURE_NONCE_OPTION_PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded cleanup reads nonce rows for replay protection maintenance.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d ORDER BY option_id ASC LIMIT %d",
				$like,
				time(),
				Controller::SIGNATURE_NONCE_CLEANUP_BATCH
			),
			ARRAY_A
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$option_name  = is_array( $row ) ? (string) ( $row['option_name'] ?? '' ) : '';
			$option_value = is_array( $row ) ? (string) ( $row['option_value'] ?? '' ) : '';
			if ( '' === $option_name || 0 !== strpos( $option_name, Controller::SIGNATURE_NONCE_OPTION_PREFIX ) || ! is_numeric( $option_value ) || (int) $option_value >= time() ) {
				continue;
			}

			$this->delete_expired_signature_nonce_option( $option_name, $option_value );
		}
	}
	/**
	 * Filters requested client scopes to the current adapter contract.
	 *
	 * @param array<int,mixed> $requested Requested scopes.
	 * @return array<int,string>
	 */
	public function requested_scopes( array $requested ): array {
		$allowed        = array(
			'npcink.read'    => true,
			'npcink.propose' => true,
			'npcink.status'  => true,
			'npcink.execute' => true,
		);
		$default_scopes = array( 'npcink.read', 'npcink.propose', 'npcink.status' );
		$scopes         = array();

		foreach ( $requested as $scope ) {
			$scope = sanitize_text_field( (string) $scope );
			if ( isset( $allowed[ $scope ] ) ) {
				$scopes[] = $scope;
			}
		}

		return ! empty( $scopes ) ? array_values( array_unique( $scopes ) ) : $default_scopes;
	}
	/**
	 * Returns pending device pairing records.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function pairings(): array {
		$pairings = get_option( Controller::DEVICE_PAIRING_OPTION, array() );
		return is_array( $pairings ) ? $pairings : array();
	}
	/**
	 * Removes expired pending device pairing records.
	 *
	 * @param array<string,array<string,mixed>> $pairings Pairings.
	 * @return array<string,array<string,mixed>>
	 */
	public function prune_pairings( array $pairings ): array {
		$now = time();
		foreach ( $pairings as $user_code => $pairing ) {
			if ( $now > (int) ( $pairing['expires_at'] ?? 0 ) ) {
				unset( $pairings[ $user_code ] );
			}
		}

		if ( count( $pairings ) <= Controller::MAX_DEVICE_PAIRINGS ) {
			return $pairings;
		}

		uasort(
			$pairings,
			static function ( $left, $right ): int {
				$left_time  = is_array( $left ) ? (string) ( $left['created_at'] ?? '' ) : '';
				$right_time = is_array( $right ) ? (string) ( $right['created_at'] ?? '' ) : '';

				return strcmp( $left_time, $right_time );
			}
		);

		return array_slice( $pairings, - Controller::MAX_DEVICE_PAIRINGS, null, true );
	}
	/**
	 * Returns a pending device pairing by device code.
	 *
	 * @param string $device_code Device code.
	 * @return array<string,mixed>
	 */
	public function pairing_by_device_code( string $device_code ): array {
		if ( '' === $device_code ) {
			return array();
		}

		$hash = hash( 'sha256', $device_code );
		foreach ( $this->pairings() as $pairing ) {
			if ( hash_equals( (string) ( $pairing['device_code_hash'] ?? '' ), $hash ) ) {
				return $pairing;
			}
		}

		return array();
	}
	/**
	 * Returns a device pairing record by user code for admin display.
	 *
	 * @param string $user_code User code.
	 * @return array<string,mixed>
	 */
	public function admin_pairing( string $user_code ): array {
		$user_code = strtoupper( sanitize_text_field( $user_code ) );
		$pairings  = $this->pairings();
		$pairing   = is_array( $pairings[ $user_code ] ?? null ) ? $pairings[ $user_code ] : array();
		if ( ! empty( $pairing ) && time() <= (int) ( $pairing['expires_at'] ?? 0 ) ) {
			return $pairing;
		}

		return array();
	}
	/**
	 * Approves a device pairing for the current administrator.
	 *
	 * @param string $user_code   User code.
	 * @param string $admin_label Optional administrator label.
	 * @return array<string,mixed>|WP_Error
	 */
	public function approve_pairing( string $user_code, string $admin_label = '' ) {
		$started     = microtime( true );
		$user_code   = strtoupper( sanitize_text_field( $user_code ) );
		$admin_label = $this->bounded_text_field( $admin_label, 80 );
		$pairings    = $this->pairings();
		$pairing     = is_array( $pairings[ $user_code ] ?? null ) ? $pairings[ $user_code ] : array();
		if ( empty( $pairing ) || time() > (int) ( $pairing['expires_at'] ?? 0 ) ) {
			$error = new WP_Error( 'npcink_openclaw_adapter_pairing_not_found', __( 'Device pairing was not found or expired.', 'npcink-ai-client-adapter' ) );
			$this->emit( 'adapter.device_pairing.approve', $started, $error );
			return $error;
		}

		$user_id       = get_current_user_id();
		$key           = is_array( $pairing['key'] ?? null ) ? $pairing['key'] : array();
		$client        = is_array( $pairing['client'] ?? null ) ? $pairing['client'] : array();
		$public_key    = (string) ( $key['public_key'] ?? '' );
		$fingerprint   = (string) ( $key['fingerprint'] ?? '' );
		$key_id        = 'mk_' . substr( hash( 'sha256', rest_url( Controller::NAMESPACE ) . '|' . $user_id . '|' . $fingerprint ), 0, 24 );
		$connection_id = 'npcink_conn_' . substr( hash( 'sha256', home_url() . '|' . $key_id ), 0, 24 );
		$record        = array(
			'key_id'         => $key_id,
			'connection_id'  => $connection_id,
			'admin_label'    => $admin_label,
			'user_id'        => $user_id,
			'client_name'    => (string) ( $client['name'] ?? '' ),
			'device_name'    => (string) ( $client['device_name'] ?? '' ),
			'broker'         => (string) ( $client['broker'] ?? '' ),
			'broker_version' => (string) ( $client['broker_version'] ?? '' ),
			'public_key'     => $public_key,
			'fingerprint'    => $fingerprint,
			'scopes'         => is_array( $pairing['scopes'] ?? null ) ? array_values( $pairing['scopes'] ) : array(),
			'created_at'     => gmdate( 'c' ),
			'last_used_at'   => '',
			'revoked_at'     => '',
		);

		$keys            = $this->key_records();
		$keys[ $key_id ] = $record;
		update_option( Controller::CLIENT_KEYS_OPTION, $keys, false );

		$pairing['status']               = 'approved';
		$pairing['approved_at']          = gmdate( 'c' );
			$pairing['approved_user_id'] = $user_id;
			$pairing['key_id']           = $key_id;
			$pairing['connection_id']    = $connection_id;
			$pairing['admin_label']      = $admin_label;
			$pairing['scopes_effective'] = $record['scopes'];
		$pairings[ $user_code ]          = $pairing;
		update_option( Controller::DEVICE_PAIRING_OPTION, $this->prune_pairings( $pairings ), false );

		$this->emit( 'adapter.device_pairing.approve', $started, null );

		return $this->public_key_record( $record );
	}
	/**
	 * Rejects a device pairing.
	 *
	 * @param string $user_code User code.
	 * @return bool
	 */
	public function reject_pairing( string $user_code ): bool {
		$started   = microtime( true );
		$user_code = strtoupper( sanitize_text_field( $user_code ) );
		$pairings  = $this->pairings();
		if ( ! is_array( $pairings[ $user_code ] ?? null ) ) {
			$this->emit(
				'adapter.device_pairing.reject',
				$started,
				new WP_Error( 'npcink_openclaw_adapter_pairing_not_found', __( 'Device pairing was not found or expired.', 'npcink-ai-client-adapter' ) )
			);
			return false;
		}

		$pairings[ $user_code ]['status']      = 'rejected';
		$pairings[ $user_code ]['rejected_at'] = gmdate( 'c' );
		update_option( Controller::DEVICE_PAIRING_OPTION, $this->prune_pairings( $pairings ), false );

		$this->emit( 'adapter.device_pairing.reject', $started, null );

		return true;
	}
	/**
	 * Returns stored client keys.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function key_records(): array {
		$records = get_option( Controller::CLIENT_KEYS_OPTION, array() );
		return is_array( $records ) ? $records : array();
	}
	/**
	 * Returns whether the last-used timestamp should be persisted again.
	 *
	 * @param string $last_used_at Last persisted timestamp.
	 * @return bool
	 */
	public function should_update_key_last_used( string $last_used_at ): bool {
		$last_used = strtotime( $last_used_at );
		if ( false === $last_used ) {
			return true;
		}

		return ( time() - $last_used ) >= Controller::CLIENT_KEY_LAST_USED_WRITE_TTL;
	}
	/**
	 * Returns a public-safe client key record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return array<string,mixed>
	 */
	public function public_key_record( array $record ): array {
		return array(
			'key_id'        => (string) ( $record['key_id'] ?? '' ),
			'connection_id' => (string) ( $record['connection_id'] ?? '' ),
			'admin_label'   => (string) ( $record['admin_label'] ?? '' ),
			'client_name'   => (string) ( $record['client_name'] ?? '' ),
			'device_name'   => (string) ( $record['device_name'] ?? '' ),
			'fingerprint'   => (string) ( $record['fingerprint'] ?? '' ),
			'scopes'        => is_array( $record['scopes'] ?? null ) ? array_values( $record['scopes'] ) : array(),
			'created_at'    => (string) ( $record['created_at'] ?? '' ),
			'last_used_at'  => (string) ( $record['last_used_at'] ?? '' ),
			'revoked_at'    => (string) ( $record['revoked_at'] ?? '' ),
		);
	}
	/**
	 * Returns whether client key scopes allow a request.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param WP_REST_Request     $request Request.
	 * @return bool
	 */
	public function key_scope_allows( array $record, WP_REST_Request $request ): bool {
		$scopes = array_fill_keys( is_array( $record['scopes'] ?? null ) ? $record['scopes'] : array(), true );
		$route  = $request->get_route();
		$method = strtoupper( $request->get_method() );

		// The unified approve-and-execute action holds approval authority and is
		// reserved for WordPress administrator sessions. No client key scope can
		// ever allow it. See docs/threat-model.md.
		if ( false !== strpos( $route, '/approve-and-execute' ) ) {
			return false;
		}

		if ( 'POST' === $method && $this->route_requires_execute_scope( $route ) ) {
			return ! empty( $scopes['npcink.execute'] ) || ! empty( $scopes['magick.execute'] );
		}

		if ( false !== strpos( $route, '/proposals' ) ) {
			return ! empty( $scopes['npcink.propose'] ) || ! empty( $scopes['magick.propose'] );
		}

		if ( 'GET' === $method && ( false !== strpos( $route, '/health' ) || false !== strpos( $route, '/help' ) || false !== strpos( $route, '/capabilities' ) || false !== strpos( $route, '/connection/' ) ) ) {
			return ! empty( $scopes['npcink.status'] ) || ! empty( $scopes['magick.status'] );
		}

		return ! empty( $scopes['npcink.read'] ) || ! empty( $scopes['magick.read'] );
	}
	/**
	 * Returns whether a signed client request consumes final execution authority.
	 *
	 * @param string $route REST route.
	 * @return bool
	 */
	public function route_requires_execute_scope( string $route ): bool {
		return false !== strpos( $route, '/execute-approved-proposal' )
			|| false !== strpos( $route, '/commit-preflight' )
			|| ( false !== strpos( $route, '/proposals/' ) && false !== strpos( $route, '/execute' ) );
	}
	/**
	 * Revokes a client key by id for a user.
	 *
	 * @param string $key_id Key id.
	 * @param int    $user_id User id.
	 * @return array<string,mixed>|WP_Error
	 */
	public function revoke_key_by_id( string $key_id, int $user_id ) {
		$key_id = sanitize_text_field( $key_id );
		$keys   = $this->key_records();
		$record = is_array( $keys[ $key_id ] ?? null ) ? $keys[ $key_id ] : array();
		if ( empty( $record ) || (int) ( $record['user_id'] ?? 0 ) !== $user_id ) {
			return new WP_Error(
				'npcink_openclaw_adapter_client_key_not_found',
				__( 'Client key was not found for the current user.', 'npcink-ai-client-adapter' ),
				array( 'status' => 404 )
			);
		}

		$record['revoked_at'] = gmdate( 'c' );
		$keys[ $key_id ]      = $record;
		update_option( Controller::CLIENT_KEYS_OPTION, $keys, false );

		return $this->public_key_record( $record );
	}
	/**
	 * Returns public client key records for an admin user.
	 *
	 * @param int $user_id User id.
	 * @return array<int,array<string,mixed>>
	 */
	public function admin_client_keys( int $user_id ): array {
		$records = array();
		foreach ( $this->key_records() as $record ) {
			if ( (int) ( $record['user_id'] ?? 0 ) === $user_id ) {
				$records[] = $this->public_key_record( $record );
			}
		}

		return $records;
	}
	/**
	 * Applies a lightweight public pairing start rate limit.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function enforce_start_rate_limit( WP_REST_Request $request ) {
		$key   = 'npcink_openclaw_adapter_pairing_start_' . md5( $this->rate_limit_fingerprint() );
		$count = absint( get_transient( $key ) );
		if ( $count >= Controller::MAX_DEVICE_PAIRING_STARTS_PER_WINDOW ) {
			return new WP_Error(
				'npcink_openclaw_adapter_device_pairing_rate_limited',
				__( 'Too many device pairing attempts. Try again shortly.', 'npcink-ai-client-adapter' ),
				array(
					'status'       => 429,
					'retry_after'  => Controller::DEVICE_PAIRING_RATE_LIMIT_TTL,
					'window'       => Controller::DEVICE_PAIRING_RATE_LIMIT_TTL,
					'max_attempts' => Controller::MAX_DEVICE_PAIRING_STARTS_PER_WINDOW,
				)
			);
		}

		set_transient( $key, $count + 1, Controller::DEVICE_PAIRING_RATE_LIMIT_TTL );

		return true;
	}
	/**
	 * Applies a lightweight public pairing poll rate limit.
	 *
	 * @param string $device_code Device code.
	 * @return true|WP_Error
	 */
	public function enforce_poll_rate_limit( string $device_code ) {
		$key   = 'npcink_openclaw_adapter_pairing_poll_' . md5( $this->rate_limit_fingerprint() . '|' . hash( 'sha256', $device_code ) );
		$count = absint( get_transient( $key ) );
		if ( $count >= Controller::MAX_DEVICE_PAIRING_POLLS_PER_WINDOW ) {
			return new WP_Error(
				'npcink_openclaw_adapter_device_pairing_poll_rate_limited',
				__( 'Too many device pairing polls. Try again shortly.', 'npcink-ai-client-adapter' ),
				array(
					'status'       => 429,
					'retry_after'  => Controller::DEVICE_PAIRING_POLL_RATE_LIMIT_TTL,
					'window'       => Controller::DEVICE_PAIRING_POLL_RATE_LIMIT_TTL,
					'max_attempts' => Controller::MAX_DEVICE_PAIRING_POLLS_PER_WINDOW,
				)
			);
		}

		set_transient( $key, $count + 1, Controller::DEVICE_PAIRING_POLL_RATE_LIMIT_TTL );

		return true;
	}
	/**
	 * Returns a coarse local request fingerprint for unauthenticated throttles.
	 *
	 * @return string
	 */
	public function rate_limit_fingerprint(): string {
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		return '' !== $remote_addr ? $remote_addr : 'unknown';
	}
}
