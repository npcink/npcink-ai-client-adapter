<?php
/**
 * Read governance domain service: sensitivity classification, redaction,
 * and Core read-authorization context validation.
 *
 * Owns the sensitive-read boundary — classifying ability sensitivity,
 * bounding and redacting read results, and verifying that Core-issued
 * read grants bind to this site and the current signed client. Read
 * policy truth stays with Core capability metadata.
 *
 * @package NpcinkOpenClawAdapter
 */

namespace Npcink\OpenClawAdapter\Rest;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classifies, bounds, and redacts governed read results.
 */
final class Read_Governance {

	/**
	 * Dependency introspection service for scalar list sanitization.
	 *
	 * @var Dependency_Status
	 */
	private $dependency_status;

	/**
	 * Signing auth service for fingerprint sanitization.
	 *
	 * @var Signing_Auth
	 */
	private $signing_auth;

	/**
	 * Preflight handoff service for Core context binding validation.
	 *
	 * @var Preflight_Handoffs
	 */
	private $preflight_handoffs;

	/**
	 * Creates the read governance service.
	 *
	 * @param Dependency_Status  $dependency_status Dependency introspection service.
	 * @param Signing_Auth       $signing_auth Signing auth service.
	 * @param Preflight_Handoffs $preflight_handoffs Preflight handoff service.
	 */
	public function __construct( Dependency_Status $dependency_status, Signing_Auth $signing_auth, Preflight_Handoffs $preflight_handoffs ) {
		$this->dependency_status  = $dependency_status;
		$this->signing_auth       = $signing_auth;
		$this->preflight_handoffs = $preflight_handoffs;
	}

	/**
	 * Returns whether an array is a list.
	 *
	 * @param array<mixed> $value Value.
	 * @return bool
	 */
	public function is_list_array( array $value ): bool {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $value );
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Returns whether a key is structural and should survive allowed-field filtering.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public function is_read_result_structural_key( string $key ): bool {
		return in_array(
			$key,
			array( 'ok', 'status', 'data', 'result', 'results', 'items', 'rows', 'entries', 'summary', 'meta', 'metadata', 'counts', 'count', 'total' ),
			true
		);
	}

	/**
	 * Returns whether a read result key must be redacted.
	 *
	 * @param string $key Result key.
	 * @return bool
	 */
	public function is_sensitive_read_key( string $key ): bool {
		$key = strtolower( $key );
		foreach ( array( 'password', 'pass', 'secret', 'token', 'authorization', 'cookie', 'nonce', 'user_email', 'email', 'api_key', 'private_key' ) as $needle ) {
			if ( false !== strpos( $key, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Infers read sensitivity when Core capability guidance predates read policy.
	 *
	 * @param string $ability_id Ability id.
	 * @return string
	 */
	public function infer_sensitivity( string $ability_id ): string {
		$ability_id = strtolower( $ability_id );

		foreach ( array( 'diagnostic', 'permissions', 'database', 'error-log', 'plugin-conflict', 'ops' ) as $needle ) {
			if ( false !== strpos( $ability_id, $needle ) ) {
				return 'sensitive';
			}
		}

		foreach ( array( 'inventory', 'plan', 'media', 'pages', 'posts', 'users', 'menu', 'term', 'workflow' ) as $needle ) {
			if ( false !== strpos( $ability_id, $needle ) ) {
				return 'internal';
			}
		}

		return 'public';
	}

	/**
	 * Identifies the bounded non-secret authorization envelope used by Core-ready plans.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	public function is_safe_authorization_envelope( $value ): bool {
		if ( ! is_array( $value ) || array() !== array_diff( array_keys( $value ), array( 'classification', 'authority' ) ) ) {
			return false;
		}

		return 'core_proposal_required' === ( $value['classification'] ?? null )
			&& 'npcink-governance-core' === ( $value['authority'] ?? null );
	}

	/**
	 * Redacts sensitive values in a read result.
	 *
	 * @param mixed $value Value.
	 * @param int   $count Redacted field count.
	 * @param array<int,string> $denied_fields Denied fields from Core.
	 * @return mixed
	 */
	public function redact_value( $value, int &$count, array $denied_fields = array() ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$denied_fields = array_map( 'strtolower', $denied_fields );
		$clean         = array();
		foreach ( $value as $key => $item ) {
			$key_string     = is_string( $key ) ? $key : (string) $key;
			$key_normalized = strtolower( $key_string );
			if ( 'authorization' === $key_normalized && ! in_array( $key_normalized, $denied_fields, true ) && $this->is_safe_authorization_envelope( $item ) ) {
				$clean[ $key ] = $item;
				continue;
			}
			if ( in_array( $key_normalized, $denied_fields, true ) || $this->is_sensitive_read_key( $key_string ) ) {
				$clean[ $key ] = '[REDACTED]';
				++$count;
				continue;
			}

			$clean[ $key ] = $this->redact_value( $item, $count, $denied_fields );
		}

		return $clean;
	}

	/**
	 * Applies Core read bounds to a result tree.
	 *
	 * @param mixed               $value Value.
	 * @param array<string,mixed> $bounds Bounds.
	 * @param int                 $count Redaction count.
	 * @return mixed
	 */
	public function apply_bounds( $value, array $bounds, int &$count ) {
		$max_rows       = absint( $bounds['max_rows'] ?? 0 );
		$tail_lines     = absint( $bounds['tail_lines'] ?? 0 );
		$allowed_fields = $this->dependency_status->sanitize_string_list( is_array( $bounds['allowed_fields'] ?? null ) ? (array) $bounds['allowed_fields'] : array() );

		if ( is_string( $value ) && $tail_lines > 0 ) {
			$lines = preg_split( '/\R/', $value );
			if ( is_array( $lines ) && count( $lines ) > $tail_lines ) {
				$value = implode( "\n", array_slice( $lines, - $tail_lines ) );
				++$count;
			}
			return $value;
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( $this->is_list_array( $value ) ) {
			$items = $max_rows > 0 && count( $value ) > $max_rows ? array_slice( $value, 0, $max_rows ) : $value;
			if ( count( $items ) !== count( $value ) ) {
				++$count;
			}
			return array_map(
				function ( $item ) use ( $bounds, &$count ) {
					return $this->apply_bounds( $item, $bounds, $count );
				},
				$items
			);
		}

		$clean = array();
		foreach ( $value as $key => $item ) {
			$key_string = is_string( $key ) ? $key : (string) $key;
			if ( ! empty( $allowed_fields ) && ! in_array( $key_string, $allowed_fields, true ) && ! $this->is_read_result_structural_key( $key_string ) ) {
				++$count;
				continue;
			}
			$clean[ $key ] = $this->apply_bounds( $item, $bounds, $count );
		}

		return $clean;
	}

	/**
	 * Applies read redaction according to Core read policy.
	 *
	 * @param mixed               $result Read result.
	 * @param array<string,mixed> $read_context Read context.
	 * @return array{result:mixed,redaction_applied:bool,redaction_summary:array<string,mixed>}
	 */
	public function apply_redaction( $result, array $read_context ): array {
		$required = (bool) ( $read_context['redaction_required'] ?? false );
		$count    = 0;
		$bounds   = is_array( $read_context['read_authorization_bounds'] ?? null ) ? (array) $read_context['read_authorization_bounds'] : array();

		if ( $required ) {
			$result = $this->apply_bounds( $result, $bounds, $count );
			$result = $this->redact_value( $result, $count, $this->dependency_status->sanitize_string_list( is_array( $bounds['denied_fields'] ?? null ) ? (array) $bounds['denied_fields'] : array() ) );
		}

		return array(
			'result'            => $result,
			'redaction_applied' => $required,
			'redaction_summary' => array(
				'policy_applied'       => $required,
				'redacted_field_count' => $count,
				'max_rows'             => absint( $bounds['max_rows'] ?? 0 ),
				'tail_lines'           => absint( $bounds['tail_lines'] ?? 0 ),
				'allowed_fields'       => $this->dependency_status->sanitize_string_list( is_array( $bounds['allowed_fields'] ?? null ) ? (array) $bounds['allowed_fields'] : array() ),
				'denied_fields'        => $this->dependency_status->sanitize_string_list( is_array( $bounds['denied_fields'] ?? null ) ? (array) $bounds['denied_fields'] : array() ),
			),
		);
	}

	/**
	 * Sanitizes a Core read authorization context for runtime use and logs.
	 *
	 * @param array<string,mixed> $context Grant context.
	 * @return array<string,mixed>
	 */
	public function sanitize_authorization_context( array $context ): array {
		$bounds = is_array( $context['bounds'] ?? null ) ? (array) $context['bounds'] : array();

		return array(
			'request_id'                 => sanitize_text_field( (string) ( $context['request_id'] ?? '' ) ),
			'ability_id'                 => sanitize_text_field( (string) ( $context['ability_id'] ?? '' ) ),
			'approved_input_hash'        => sanitize_text_field( (string) ( $context['approved_input_hash'] ?? '' ) ),
			'correlation_id'             => sanitize_text_field( (string) ( $context['correlation_id'] ?? '' ) ),
			'policy_version'             => sanitize_text_field( (string) ( $context['policy_version'] ?? '' ) ),
			'site_url'                   => sanitize_text_field( (string) ( $context['site_url'] ?? '' ) ),
			'home_url'                   => sanitize_text_field( (string) ( $context['home_url'] ?? '' ) ),
			'blog_id'                    => absint( $context['blog_id'] ?? 0 ),
			'signed_client_fingerprint'  => $this->signing_auth->sanitize_signed_client_fingerprint( (string) ( $context['signed_client_fingerprint'] ?? '' ) ),
			'client_key_fingerprint'     => $this->signing_auth->sanitize_signed_client_fingerprint( (string) ( $context['client_key_fingerprint'] ?? '' ) ),
			'sensitivity'                => sanitize_key( (string) ( $context['sensitivity'] ?? 'sensitive' ) ),
			'data_classes'               => $this->dependency_status->sanitize_string_list( is_array( $context['data_classes'] ?? null ) ? (array) $context['data_classes'] : array() ),
			'redaction_level'            => sanitize_key( (string) ( $context['redaction_level'] ?? 'strict' ) ),
			'expires_at'                 => sanitize_text_field( (string) ( $context['expires_at'] ?? '' ) ),
			'bounds'                     => array(
				'max_rows'       => absint( $bounds['max_rows'] ?? 0 ),
				'tail_lines'     => absint( $bounds['tail_lines'] ?? 0 ),
				'allowed_fields' => $this->dependency_status->sanitize_string_list( is_array( $bounds['allowed_fields'] ?? null ) ? (array) $bounds['allowed_fields'] : array() ),
				'denied_fields'  => $this->dependency_status->sanitize_string_list( is_array( $bounds['denied_fields'] ?? null ) ? (array) $bounds['denied_fields'] : array() ),
				'one_time'       => ! empty( $bounds['one_time'] ),
			),
			'read_authorization_granted' => true,
			'core_authorization_truth'   => 'npcink_governance_core',
			'commit_execution'           => false,
			'write_execution'            => false,
		);
	}

	/**
	 * Validates a Core read authorization context.
	 *
	 * @param array<string,mixed> $context Grant context.
	 * @param string              $ability_id Ability id.
	 * @param string              $request_id Request id.
	 * @return array<string,mixed>|WP_Error
	 */
	public function validate_authorization_context( array $context, string $ability_id, string $request_id ) {
		if ( true !== (bool) ( $context['read_authorization_granted'] ?? false ) ) {
			return new WP_Error(
				'npcink_openclaw_adapter_core_read_grant_not_granted',
				__( 'Core read authorization was not granted.', 'npcink-ai-client-adapter' ),
				array( 'status' => 403 )
			);
		}
		if ( 'npcink_governance_core' !== (string) ( $context['core_authorization_truth'] ?? '' ) ) {
			return new WP_Error(
				'npcink_openclaw_adapter_core_read_grant_truth_invalid',
				__( 'Core read authorization truth marker is invalid.', 'npcink-ai-client-adapter' ),
				array( 'status' => 403 )
			);
		}
		if ( (string) ( $context['ability_id'] ?? '' ) !== $ability_id || (string) ( $context['request_id'] ?? '' ) !== $request_id ) {
			return new WP_Error(
				'npcink_openclaw_adapter_core_read_grant_target_mismatch',
				__( 'Core read authorization context does not match the requested ability or read request.', 'npcink-ai-client-adapter' ),
				array( 'status' => 403 )
			);
		}
		if ( '' === sanitize_text_field( (string) ( $context['approved_input_hash'] ?? '' ) ) ) {
			return new WP_Error(
				'npcink_openclaw_adapter_core_read_grant_hash_missing',
				__( 'Core read authorization context is missing the approved input hash.', 'npcink-ai-client-adapter' ),
				array( 'status' => 403 )
			);
		}
		if ( true === (bool) ( $context['commit_execution'] ?? false ) || true === (bool) ( $context['write_execution'] ?? false ) ) {
			return new WP_Error(
				'npcink_openclaw_adapter_core_read_grant_execution_invalid',
				__( 'Core read authorization context must not enable write or commit execution.', 'npcink-ai-client-adapter' ),
				array( 'status' => 403 )
			);
		}

		$site_binding = $this->preflight_handoffs->validate_context_site_binding( $context, 'npcink_openclaw_adapter_core_read_grant', 403 );
		if ( is_wp_error( $site_binding ) ) {
			return $site_binding;
		}
		$client_binding = $this->preflight_handoffs->validate_context_signed_client( $context, 'npcink_openclaw_adapter_core_read_grant', 403 );
		if ( is_wp_error( $client_binding ) ) {
			return $client_binding;
		}

		$expires_at = strtotime( (string) ( $context['expires_at'] ?? '' ) );
		if ( false === $expires_at || $expires_at <= time() ) {
			return new WP_Error(
				'npcink_openclaw_adapter_core_read_grant_expired',
				__( 'Core read authorization context is expired.', 'npcink-ai-client-adapter' ),
				array( 'status' => 403 )
			);
		}

		return $this->sanitize_authorization_context( $context );
	}

	/**
	 * Returns whether Core requires an explicit read authorization before Adapter may run a direct-read ability.
	 *
	 * @param array<string,mixed> $capability Capability row.
	 * @return bool
	 */
	public function authorization_required( array $capability ): bool {
		$read_policy        = sanitize_key( (string) ( $capability['read_policy'] ?? '' ) );
		$governance_mode    = sanitize_key( (string) ( $capability['governance_mode'] ?? '' ) );
		$authorization_mode = sanitize_key( (string) ( $capability['authorization_mode'] ?? '' ) );
		$read_authorization = is_array( $capability['read_authorization'] ?? null ) ? $capability['read_authorization'] : array();

		return true === (bool) ( $capability['read_authorization_required'] ?? false )
			|| true === (bool) ( $capability['requires_read_authorization'] ?? false )
			|| true === (bool) ( $read_authorization['required'] ?? false )
			|| 'core_read_authorization_required' === $read_policy
			|| 'core_read_authorization_required' === $governance_mode
			|| 'core_read_request' === $authorization_mode;
	}
}
