<?php
/**
 * Focused behavior checks for Adapter trust-boundary hardening.
 *
 * @package NpcinkOpenClawAdapter
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['maa_security_options'] = array();
$GLOBALS['maa_security_user_can'] = true;

function __( $text, $domain = null ) {
	return $text;
}

function absint( $value ): int {
	return abs( (int) $value );
}

function current_user_can( $capability ): bool {
	return false;
}

function is_user_logged_in(): bool {
	return false;
}

function get_userdata( $user_id ) {
	return $user_id > 0 ? new stdClass() : false;
}

function user_can( $user, $capability ): bool {
	return $GLOBALS['maa_security_user_can'];
}

function wp_set_current_user( $user_id ) {
	return $user_id;
}

function update_option( $name, $value, $autoload = null ): bool {
	$GLOBALS['maa_security_options'][ $name ] = $value;
	return true;
}

class WP_Error {
	private $code;
	private $message;
	private $error_data;

	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code       = $code;
		$this->message    = $message;
		$this->error_data = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->error_data;
	}
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

function sanitize_key( $key ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) ?: '';
}

function sanitize_text_field( $value ): string {
	return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) ?: '' );
}

function sanitize_file_name( $value ): string {
	return (string) preg_replace( '/[^A-Za-z0-9._-]/', '', basename( (string) $value ) );
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function wp_generate_uuid4(): string {
	return '00000000-0000-4000-8000-000000000001';
}

function wp_json_encode( $value ) {
	return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['maa_security_options'] ) ? $GLOBALS['maa_security_options'][ $name ] : $default;
}

function maybe_serialize( $value ) {
	return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value;
}

function wp_rand( $min = 0, $max = 0 ): int {
	return 2;
}

final class MAA_Security_WPDB {
	public $options = 'wp_options';
	private $prepared_args = array();

	public function esc_like( string $value ): string {
		return addcslashes( $value, '_%\\' );
	}

	public function prepare( string $query, ...$args ): string {
		$this->prepared_args = $args;
		return $query;
	}

	public function query( string $query ): int {
		if ( 0 === strpos( $query, 'INSERT IGNORE INTO ' ) ) {
			$name = (string) ( $this->prepared_args[0] ?? '' );
			if ( '' === $name || array_key_exists( $name, $GLOBALS['maa_security_options'] ) ) {
				return 0;
			}

			$GLOBALS['maa_security_options'][ $name ] = $this->prepared_args[1] ?? '';
			$GLOBALS['maa_security_nonce_autoload']   = (string) ( $this->prepared_args[2] ?? '' );
			return 1;
		}

		if ( 0 === strpos( $query, 'DELETE FROM ' ) ) {
			$name     = (string) ( $this->prepared_args[0] ?? '' );
			$expected = $this->prepared_args[1] ?? null;
			if ( ! array_key_exists( $name, $GLOBALS['maa_security_options'] ) || $GLOBALS['maa_security_options'][ $name ] != $expected ) {
				return 0;
			}

			unset( $GLOBALS['maa_security_options'][ $name ] );
			return 1;
		}

		return 0;
	}

	public function get_var( string $query ) {
		$name = (string) ( $this->prepared_args[0] ?? '' );
		return array_key_exists( $name, $GLOBALS['maa_security_options'] ) ? $GLOBALS['maa_security_options'][ $name ] : null;
	}

	public function get_results( string $query, $output = null ): array {
		return array();
	}
}

$GLOBALS['wpdb'] = new MAA_Security_WPDB();

class WP_REST_Request {
	private $params;
	private $route;
	private $headers;
	private $body;
	private $method;

	public function __construct( array $params, string $route, array $headers = array(), string $body = '', string $method = 'GET' ) {
		$this->params  = $params;
		$this->route   = $route;
		$this->headers = $headers;
		$this->body    = $body;
		$this->method  = $method;
	}

	public function get_param( string $key ) {
		return $this->params[ $key ] ?? null;
	}

	public function get_route(): string {
		return $this->route;
	}

	public function get_method(): string {
		return $this->method;
	}

	public function get_header( string $key ) {
		return $this->headers[ $key ] ?? null;
	}

	public function get_body(): string {
		return $this->body;
	}
}

function maa_security_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

function maa_security_invoke( object $object, string $method, array $args = array() ) {
	$reflection = new ReflectionMethod( $object, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $object, $args );
}

function maa_security_field_count( array $value ): int {
	$count = 0;
	foreach ( $value as $item ) {
		++$count;
		if ( is_array( $item ) ) {
			$count += maa_security_field_count( $item );
		}
	}
	return $count;
}

function maa_security_contains_key_fragment( array $value, string $fragment ): bool {
	foreach ( $value as $key => $item ) {
		if ( false !== stripos( (string) $key, $fragment ) ) {
			return true;
		}
		if ( is_array( $item ) && maa_security_contains_key_fragment( $item, $fragment ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Returns one exact local11 media derivative artifact.
 *
 * @param array<string,mixed> $overrides Overrides.
 * @return array<string,mixed>
 */
function maa_security_media_derivative_artifact( array $overrides = array() ): array {
	return array_merge(
		array(
			'artifact_id'        => 'art_' . str_repeat( 'a', 32 ),
			'expires_at'         => gmdate( 'c', time() + 3600 ),
			'mime_type'          => 'image/webp',
			'format'             => 'webp',
			'width'              => 1200,
			'height'             => 800,
			'filesize_bytes'     => 210000,
			'sha256'             => str_repeat( 'b', 64 ),
			'suggested_filename' => 'adapter-readiness.webp',
			'filename_basis'     => array(
				'owner'                          => 'wordpress_write_ability_final',
				'strategy'                       => 'format_checksum',
				'final_sanitize_unique_required' => true,
			),
			'processing_warnings' => array(),
		),
		$overrides
	);
}

require_once dirname( __DIR__ ) . '/includes/Rest/Controller.php';

$reflection = new ReflectionClass( \Npcink\OpenClawAdapter\Rest\Controller::class );
$controller = $reflection->newInstanceWithoutConstructor();
$fingerprint_property = $reflection->getProperty( 'current_signed_client_fingerprint' );
$fingerprint_property->setAccessible( true );
$trusted_fingerprint = 'sha256:' . str_repeat( 'a', 64 );
$fingerprint_property->setValue( $controller, $trusted_fingerprint );

$safe_authorization = array(
	'classification' => 'core_proposal_required',
	'authority'      => 'npcink-governance-core',
);
maa_security_assert(
	true === maa_security_invoke( $controller, 'is_safe_governance_authorization_envelope', array( $safe_authorization ) ),
	'Exact Core proposal authorization envelope is safe to retain.'
);

$authorization_redaction_count = 0;
$authorization_redaction_args  = array(
	array(
		'safe_plan'             => array( 'authorization' => $safe_authorization ),
		'extra_field_case'      => array(
			'authorization' => array_merge( $safe_authorization, array( 'token' => 'hidden' ) ),
		),
		'wrong_classification'  => array(
			'authorization' => array(
				'classification' => 'direct_write_allowed',
				'authority'      => 'npcink-governance-core',
			),
		),
		'wrong_authority'       => array(
			'authorization' => array(
				'classification' => 'core_proposal_required',
				'authority'      => 'untrusted-client',
			),
		),
		'authorization_header' => 'Bearer should-not-survive',
	),
	&$authorization_redaction_count,
);
$authorization_redacted = maa_security_invoke( $controller, 'redact_read_value', $authorization_redaction_args );
maa_security_assert( $safe_authorization === ( $authorization_redacted['safe_plan']['authorization'] ?? null ), 'Exact Core proposal authorization envelope survives read redaction.' );
maa_security_assert( '[REDACTED]' === ( $authorization_redacted['extra_field_case']['authorization'] ?? null ), 'Authorization envelope with an extra secret field is fully redacted.' );
maa_security_assert( '[REDACTED]' === ( $authorization_redacted['wrong_classification']['authorization'] ?? null ), 'Authorization envelope with another classification is fully redacted.' );
maa_security_assert( '[REDACTED]' === ( $authorization_redacted['wrong_authority']['authorization'] ?? null ), 'Authorization envelope with another authority is fully redacted.' );
maa_security_assert( '[REDACTED]' === ( $authorization_redacted['authorization_header'] ?? null ), 'Ordinary authorization values remain redacted.' );
maa_security_assert( 4 === $authorization_redaction_count, 'Authorization redaction count includes every rejected authorization value.' );

$denied_authorization_count = 0;
$denied_authorization_args  = array(
	array( 'authorization' => $safe_authorization ),
	&$denied_authorization_count,
	array( 'Authorization' ),
);
$denied_authorization = maa_security_invoke( $controller, 'redact_read_value', $denied_authorization_args );
maa_security_assert( '[REDACTED]' === ( $denied_authorization['authorization'] ?? null ), 'Core denied fields override the safe governance authorization exception regardless of case.' );
maa_security_assert( 1 === $denied_authorization_count, 'Core-denied governance authorization is counted as redacted.' );

$valid_media_artifact = maa_security_media_derivative_artifact();
maa_security_assert(
	true === maa_security_invoke( $controller, 'media_derivative_artifact_contract_is_valid', array( $valid_media_artifact ) ),
	'Exact local11 media derivative artifact is readiness-valid.'
);
$legacy_id_artifact = $valid_media_artifact;
$legacy_id_artifact['id'] = $legacy_id_artifact['artifact_id'];
unset( $legacy_id_artifact['artifact_id'] );
maa_security_assert(
	false === maa_security_invoke( $controller, 'media_derivative_artifact_contract_is_valid', array( $legacy_id_artifact ) ),
	'Legacy artifact id alias is blocked by readiness.'
);
$impossible_date_artifact = maa_security_media_derivative_artifact( array( 'expires_at' => '2027-02-31T12:00:00Z' ) );
$impossible_date_check = maa_security_invoke( $controller, 'media_optimization_artifact_expiry_check', array( $impossible_date_artifact ) );
maa_security_assert(
	false === ( $impossible_date_check['ready'] ?? true )
	&& 'invalid_expires_at' === ( $impossible_date_check['status'] ?? '' )
	&& false === maa_security_invoke( $controller, 'media_derivative_artifact_contract_is_valid', array( $impossible_date_artifact ) ),
	'Impossible artifact calendar date is blocked by readiness.'
);
$non_utc_artifact = maa_security_media_derivative_artifact( array( 'expires_at' => '2099-01-01T08:00:00+08:00' ) );
$non_utc_check = maa_security_invoke( $controller, 'media_optimization_artifact_expiry_check', array( $non_utc_artifact ) );
maa_security_assert(
	false === ( $non_utc_check['ready'] ?? true )
	&& 'invalid_expires_at' === ( $non_utc_check['status'] ?? '' )
	&& false === maa_security_invoke( $controller, 'media_derivative_artifact_contract_is_valid', array( $non_utc_artifact ) ),
	'Non-UTC artifact expiry is blocked by readiness.'
);

$request = new WP_REST_Request(
	array(
		'log_context' => array(
			'proposal_id'             => 'proposal-safe',
			'correlation_id'          => 'correlation-safe',
			'external_thread_id'      => str_repeat( 'x', 500 ),
			'governance_source'       => 'forged-governance',
			'via'                     => 'forged-via',
			'ability_id'              => 'forged/ability',
			'signed_client_fingerprint' => 'sha256:' . str_repeat( 'b', 64 ),
			'authorization'           => 'Bearer should-not-survive',
			'api_key'                 => 'should-not-survive',
			'npcink_governance_core'  => array( 'read_authorization_context' => array( 'token' => 'hidden' ) ),
			'ai_provider'             => 'untrusted-provider',
		),
		'caller'      => array(
			'external_thread_id'        => 'caller-thread',
			'caller_type'               => 'forged-caller',
			'via'                       => 'forged-via',
			'ability_id'                => 'forged/ability',
			'governance_source'         => 'forged-governance',
			'signed_client_fingerprint' => 'sha256:' . str_repeat( 'c', 64 ),
			'credential'                => 'should-not-survive',
		),
	),
	'/npcink-openclaw-adapter/v1/proposals'
);

$log_context = maa_security_invoke( $controller, 'request_log_context', array( $request, 'npcink-abilities-toolkit/create-draft' ) );
maa_security_assert( 'proposal-safe' === ( $log_context['proposal_id'] ?? '' ), 'Public proposal id annotation is retained.' );
maa_security_assert( 'npcink-governance-core' === ( $log_context['governance_source'] ?? '' ), 'Governance source is Adapter-derived.' );
maa_security_assert( 'npcink-ai-client-adapter' === ( $log_context['via'] ?? '' ), 'Transport provenance is Adapter-derived.' );
maa_security_assert( 'npcink-abilities-toolkit/create-draft' === ( $log_context['ability_id'] ?? '' ), 'Ability id is Adapter-derived.' );
maa_security_assert( 200 === strlen( (string) ( $log_context['external_thread_id'] ?? '' ) ), 'Client strings are capped at 200 bytes.' );
maa_security_assert( ! isset( $log_context['ai_provider'] ), 'Non-allowlisted provider attribution is removed.' );
maa_security_assert( 'proposal-safe' === ( $log_context['npcink_governance_core']['proposal_id'] ?? '' ), 'Nested Core proposal id is Adapter-derived.' );
maa_security_assert( 'correlation-safe' === ( $log_context['npcink_governance_core']['correlation_id'] ?? '' ), 'Nested Core correlation id is Adapter-derived.' );
maa_security_assert( ! isset( $log_context['npcink_governance_core']['read_authorization_context'] ), 'Client cannot inject nested Core authorization context.' );

$caller = maa_security_invoke( $controller, 'proposal_caller_context', array( $request, 'npcink-abilities-toolkit/create-draft' ) );
maa_security_assert( 'caller-thread' === ( $caller['external_thread_id'] ?? '' ), 'Caller annotation allowlist is retained.' );
maa_security_assert( 'openclaw_adapter' === ( $caller['caller_type'] ?? '' ), 'Caller type cannot be forged.' );
maa_security_assert( 'npcink-ai-client-adapter' === ( $caller['via'] ?? '' ), 'Caller transport cannot be forged.' );
maa_security_assert( 'npcink-governance-core' === ( $caller['governance_source'] ?? '' ), 'Caller governance source cannot be forged.' );
maa_security_assert( 'npcink-abilities-toolkit/create-draft' === ( $caller['ability_id'] ?? '' ), 'Caller ability id cannot be forged.' );
maa_security_assert( $trusted_fingerprint === ( $caller['signed_client_fingerprint'] ?? '' ), 'Caller signed fingerprint is Adapter-derived.' );
maa_security_assert( ! isset( $caller['credential'] ), 'Caller secret-bearing fields are removed.' );

$oversized = array();
for ( $index = 0; $index < 40; ++$index ) {
	$oversized[ 'field_' . $index ] = str_repeat( 'z', 400 );
}
$oversized['password'] = 'hidden';
$oversized['nested']   = array(
	'cookie' => 'hidden',
	'safe'   => array(
		'signature' => 'hidden',
		'deep'      => array( 'value' => 'dropped' ),
	),
);
$bounded = maa_security_invoke( $controller, 'sanitize_log_context', array( $oversized ) );
maa_security_assert( maa_security_field_count( $bounded ) <= 32, 'Final log context is capped at 32 fields.' );
maa_security_assert( strlen( (string) wp_json_encode( $bounded ) ) <= 8192, 'Final log context is capped at 8 KiB serialized.' );
foreach ( array( 'password', 'token', 'secret', 'authorization', 'cookie', 'nonce', 'signature', 'private_key', 'api_key', 'credential' ) as $fragment ) {
	maa_security_assert( ! maa_security_contains_key_fragment( $bounded, $fragment ), 'Sensitive log key is removed: ' . $fragment );
}
$deep_context = maa_security_invoke(
	$controller,
	'sanitize_log_context',
	array(
		array(
			'level_one' => array(
				'level_two' => array(
					'level_three' => array( 'value' => 'dropped' ),
				),
			),
		)
	)
);
maa_security_assert( ! isset( $deep_context['level_one']['level_two']['level_three'] ), 'Log context drops arrays deeper than two nested levels.' );

$previous_token = getenv( 'NPCINK_OPENCLAW_ADAPTER_CORE_APP_TOKEN' );
putenv( 'NPCINK_OPENCLAW_ADAPTER_CORE_APP_TOKEN=environment-token' );
maa_security_assert( 'environment' === maa_security_invoke( $controller, 'core_app_token_source' ), 'Core app token accepts environment source.' );
maa_security_assert( 'environment-token' === maa_security_invoke( $controller, 'core_app_token' ), 'Core app token reads environment value.' );
putenv( 'NPCINK_OPENCLAW_ADAPTER_CORE_APP_TOKEN' );
$GLOBALS['maa_security_options']['npcink_openclaw_adapter_core_app_token'] = 'legacy-option-token';
maa_security_assert( 'none' === maa_security_invoke( $controller, 'core_app_token_source' ), 'Legacy plaintext option is ignored.' );
maa_security_assert( '' === maa_security_invoke( $controller, 'core_app_token' ), 'Legacy plaintext option is not returned.' );
if ( false !== $previous_token ) {
	putenv( 'NPCINK_OPENCLAW_ADAPTER_CORE_APP_TOKEN=' . $previous_token );
}

$nonce = 'nonce-1';
$first_claim  = maa_security_invoke( $controller, 'claim_signature_nonce', array( 'mk_test', $nonce ) );
$second_claim = maa_security_invoke( $controller, 'claim_signature_nonce', array( 'mk_test', $nonce ) );
maa_security_assert( true === $first_claim, 'First verified nonce claim succeeds.' );
maa_security_assert( false === $second_claim, 'Duplicate verified nonce claim fails closed.' );
maa_security_assert( 'off' === ( $GLOBALS['maa_security_nonce_autoload'] ?? '' ), 'Nonce option is stored with autoload disabled.' );

$expired_nonce = 'nonce-expired';
$expired_name  = \Npcink\OpenClawAdapter\Rest\Controller::SIGNATURE_NONCE_OPTION_PREFIX . hash( 'sha256', 'mk_test|' . $expired_nonce );
$GLOBALS['maa_security_options'][ $expired_name ] = time() - 1;
maa_security_assert( true === maa_security_invoke( $controller, 'claim_signature_nonce', array( 'mk_test', $expired_nonce ) ), 'Expired nonce claim is conditionally reclaimed.' );
maa_security_assert( (int) $GLOBALS['maa_security_options'][ $expired_name ] > time(), 'Reclaimed nonce stores a fresh future expiry.' );

/**
 * Returns base64url for the security behavior fixtures.
 *
 * @param string $bytes Raw bytes.
 * @return string
 */
function maa_security_base64url( string $bytes ): string {
	return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
}

/**
 * Seeds the fixture client key record bound to a real Ed25519 key pair.
 *
 * @param array<string,mixed> $overrides Record overrides.
 * @return void
 */
function maa_security_seed_client_key( array $overrides = array() ): void {
	$GLOBALS['maa_security_options']['npcink_openclaw_adapter_client_keys'] = array(
		'mk_security_test' => array_merge(
			array(
				'user_id'      => 1,
				'revoked_at'   => '',
				'scopes'       => array( 'npcink.read', 'npcink.status' ),
				'public_key'   => maa_security_base64url( sodium_crypto_sign_publickey( $GLOBALS['maa_security_signing_keypair'] ) ),
				'fingerprint'  => 'sha256:' . str_repeat( 'a', 64 ),
				'last_used_at' => gmdate( 'c' ),
			),
			$overrides
		),
	);
}

/**
 * Returns signed headers carrying a real Ed25519 signature over the exact
 * canonical string the Controller verifies.
 *
 * @param object  $controller Controller instance.
 * @param string  $nonce Nonce value.
 * @param string  $route REST route.
 * @param string  $body Body bytes.
 * @param array<string,string> $overrides Header overrides.
 * @param string  $method HTTP method.
 * @return array<string,string>
 */
function maa_security_valid_signed_headers( object $controller, string $nonce, string $route = '/npcink-openclaw-adapter/v1/health', string $body = '', array $overrides = array(), string $method = 'GET' ): array {
	$timestamp    = gmdate( 'c' );
	$content_hash = 'sha256:' . hash( 'sha256', $body );
	$draft        = new WP_REST_Request( array(), $route, array(), $body, $method );
	$canonical    = maa_security_invoke( $controller, 'signed_request_canonical_string', array( $draft, $timestamp, $nonce, $content_hash ) );
	$signature    = maa_security_base64url( sodium_crypto_sign_detached( $canonical, sodium_crypto_sign_secretkey( $GLOBALS['maa_security_signing_keypair'] ) ) );

	return array_merge(
		array(
			'x_npcink_key_id'         => 'mk_security_test',
			'x_npcink_timestamp'      => $timestamp,
			'x_npcink_nonce'          => $nonce,
			'x_npcink_content_sha256' => $content_hash,
			'x_npcink_signature_alg'  => 'Ed25519',
			'x_npcink_signature'      => $signature,
		),
		$overrides
	);
}

if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
	fwrite( STDOUT, "Signed request auth behavior: skipped (PHP sodium extension unavailable)\n" );
} else {
	$_GET = array();
	$GLOBALS['maa_security_signing_keypair'] = sodium_crypto_sign_keypair();

	$nonce_counter = 0;
	$next_nonce = function () use ( &$nonce_counter ) {
		++$nonce_counter;
		return 'sec-nonce-' . $nonce_counter;
	};

	maa_security_seed_client_key();

	$malformed = maa_security_invoke(
		$controller,
		'authenticate_signed_request',
		array( new WP_REST_Request( array(), '/npcink-openclaw-adapter/v1/health', maa_security_valid_signed_headers( $controller, $next_nonce(), '/npcink-openclaw-adapter/v1/health', '', array( 'x_npcink_signature_alg' => '' ) ), '', 'GET' ) )
	);
	maa_security_assert( is_wp_error( $malformed ) && 'npcink_openclaw_adapter_signed_request_malformed' === $malformed->get_error_code(), 'Incomplete signature credentials fail with the structured malformed code.' );
	maa_security_assert( 401 === ( $malformed->get_error_data()['status'] ?? 0 ) && 'credentials_incomplete' === ( $malformed->get_error_data()['reason'] ?? '' ), 'Malformed signed request reports status and reason.' );

	$GLOBALS['maa_security_options']['npcink_openclaw_adapter_client_keys'] = array();
	$unknown_key = maa_security_invoke(
		$controller,
		'authenticate_signed_request',
		array( new WP_REST_Request( array(), '/npcink-openclaw-adapter/v1/health', maa_security_valid_signed_headers( $controller, $next_nonce(), '/npcink-openclaw-adapter/v1/health', '', array( 'x_npcink_key_id' => 'mk_missing' ) ), '', 'GET' ) )
	);
	maa_security_assert( is_wp_error( $unknown_key ) && 'npcink_openclaw_adapter_signed_request_rejected' === $unknown_key->get_error_code(), 'Unknown key id fails with the shared pre-verification rejection code.' );

	maa_security_seed_client_key( array( 'revoked_at' => gmdate( 'c' ) ) );
	$revoked_key = maa_security_invoke(
		$controller,
		'authenticate_signed_request',
		array( new WP_REST_Request( array(), '/npcink-openclaw-adapter/v1/health', maa_security_valid_signed_headers( $controller, $next_nonce() ), '', 'GET' ) )
	);
	maa_security_assert( is_wp_error( $revoked_key ) && 'npcink_openclaw_adapter_signed_request_rejected' === $revoked_key->get_error_code(), 'Revoked key id shares the pre-verification rejection code and does not confirm key existence.' );

	$GLOBALS['maa_security_user_can'] = false;
	maa_security_seed_client_key();
	$owner_demoted = maa_security_invoke(
		$controller,
		'authenticate_signed_request',
		array( new WP_REST_Request( array(), '/npcink-openclaw-adapter/v1/health', maa_security_valid_signed_headers( $controller, $next_nonce() ), '', 'GET' ) )
	);
	maa_security_assert( is_wp_error( $owner_demoted ) && 'npcink_openclaw_adapter_signed_request_rejected' === $owner_demoted->get_error_code(), 'Key whose owner lost manage_options fails with the shared rejection code.' );
	$GLOBALS['maa_security_user_can'] = true;

	maa_security_seed_client_key();
	$scope_denied = maa_security_invoke(
		$controller,
		'authenticate_signed_request',
		array( new WP_REST_Request( array(), '/npcink-openclaw-adapter/v1/proposals', maa_security_valid_signed_headers( $controller, $next_nonce(), '/npcink-openclaw-adapter/v1/proposals' ), '', 'GET' ) )
	);
	maa_security_assert( is_wp_error( $scope_denied ) && 'npcink_openclaw_adapter_signed_request_scope_denied' === $scope_denied->get_error_code(), 'Route outside the granted scopes fails with the scope-denied code.' );
	maa_security_assert( 403 === ( $scope_denied->get_error_data()['status'] ?? 0 ), 'Scope denial keeps the forbidden status.' );

	maa_security_seed_client_key();
	$skewed = maa_security_invoke(
		$controller,
		'authenticate_signed_request',
		array( new WP_REST_Request( array(), '/npcink-openclaw-adapter/v1/health', maa_security_valid_signed_headers( $controller, $next_nonce(), '/npcink-openclaw-adapter/v1/health', '', array( 'x_npcink_timestamp' => gmdate( 'c', time() - 3600 ) ) ), '', 'GET' ) )
	);
	maa_security_assert( is_wp_error( $skewed ) && 'npcink_openclaw_adapter_signed_request_timestamp_skew' === $skewed->get_error_code() && 'timestamp_outside_window' === ( $skewed->get_error_data()['reason'] ?? '' ), 'Stale signed timestamp fails with the skew code and reason.' );

	maa_security_seed_client_key();
	$hash_mismatch = maa_security_invoke(
		$controller,
		'authenticate_signed_request',
		array( new WP_REST_Request( array(), '/npcink-openclaw-adapter/v1/health', maa_security_valid_signed_headers( $controller, $next_nonce(), '/npcink-openclaw-adapter/v1/health', 'actual-body' ), 'different-body', 'GET' ) )
	);
	maa_security_assert( is_wp_error( $hash_mismatch ) && 'npcink_openclaw_adapter_signed_request_content_hash_mismatch' === $hash_mismatch->get_error_code(), 'Body hash mismatch fails with the content-hash code.' );

	maa_security_seed_client_key();
	$bad_signature = maa_security_invoke(
		$controller,
		'authenticate_signed_request',
		array( new WP_REST_Request( array(), '/npcink-openclaw-adapter/v1/health', maa_security_valid_signed_headers( $controller, $next_nonce(), '/npcink-openclaw-adapter/v1/health', '', array( 'x_npcink_signature' => maa_security_base64url( str_repeat( 'c', 64 ) ) ) ), '', 'GET' ) )
	);
	maa_security_assert( is_wp_error( $bad_signature ) && 'npcink_openclaw_adapter_signed_request_rejected' === $bad_signature->get_error_code(), 'Failed Ed25519 verification fails with the shared rejection code.' );
	maa_security_assert( $bad_signature->get_error_code() === $unknown_key->get_error_code() && $bad_signature->get_error_code() === $revoked_key->get_error_code(), 'Key state is not observable before a verified signature: unknown, revoked, and bad-signature failures share one code.' );

	$nonce_value = 'sec-nonce-replay';
	maa_security_seed_client_key();
	$first_replay_request  = new WP_REST_Request( array(), '/npcink-openclaw-adapter/v1/health', maa_security_valid_signed_headers( $controller, $nonce_value ), '', 'GET' );
	$second_replay_request = new WP_REST_Request( array(), '/npcink-openclaw-adapter/v1/health', maa_security_valid_signed_headers( $controller, $nonce_value ), '', 'GET' );
	maa_security_invoke( $controller, 'authenticate_signed_request', array( $first_replay_request ) );
	$replayed = maa_security_invoke( $controller, 'authenticate_signed_request', array( $second_replay_request ) );
	maa_security_assert( is_wp_error( $replayed ) && 'npcink_openclaw_adapter_signed_request_nonce_replayed' === $replayed->get_error_code(), 'Replayed nonce fails with the nonce-replayed code.' );

	maa_security_seed_client_key();
	$success_request = new WP_REST_Request( array(), '/npcink-openclaw-adapter/v1/health', maa_security_valid_signed_headers( $controller, $next_nonce() ), '', 'GET' );
	$success = maa_security_invoke( $controller, 'authenticate_signed_request', array( $success_request ) );
	maa_security_assert( true === $success, 'Complete valid signed request still authenticates.' );
	maa_security_assert( $trusted_fingerprint === $fingerprint_property->getValue( $controller ), 'Successful signed request records the client fingerprint.' );

	fwrite( STDOUT, "Signed request auth behavior: ok\n" );
}

fwrite( STDOUT, "Security hardening behavior: ok\n" );
