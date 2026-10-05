<?php
/**
 * Core commit-preflight handoff storage and binding validation domain service.
 *
 * Owns the bounded one-time preflight handoff cache and every Core context
 * binding check (site, expiry, signed client) that gates Adapter execution
 * on Core-issued evidence. Approval truth stays in Core; this service only
 * verifies and caches what Core already issued.
 *
 * @package NpcinkOpenClawAdapter
 */

namespace Npcink\OpenClawAdapter\Rest;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores preflight handoffs and validates Core context bindings.
 */
final class Preflight_Handoffs {

	/**
	 * Execution record storage service for adapter-scoped keys.
	 *
	 * @var Execution_Records
	 */
	private $execution_records;

	/**
	 * Signing auth service for fingerprint sanitization.
	 *
	 * @var Signing_Auth
	 */
	private $signing_auth;

	/**
	 * Returns the request-scoped signed client fingerprint.
	 *
	 * @var callable
	 */
	private $fingerprint_provider;

	/**
	 * Creates the preflight handoff service.
	 *
	 * @param Execution_Records $execution_records Execution record storage.
	 * @param Signing_Auth      $signing_auth Signing auth service.
	 * @param callable          $fingerprint_provider Request-scoped signed client fingerprint provider.
	 */
	public function __construct( Execution_Records $execution_records, Signing_Auth $signing_auth, callable $fingerprint_provider ) {
		$this->execution_records    = $execution_records;
		$this->signing_auth         = $signing_auth;
		$this->fingerprint_provider = $fingerprint_provider;
	}

	/**
	 * Returns stored preflight handoffs issued through Adapter.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function handoffs(): array {
		$records = get_option( Controller::PREFLIGHT_HANDOFFS_OPTION, array() );
		return is_array( $records ) ? $records : array();
	}
	/**
	 * Removes old preflight handoffs after the bounded retention limit.
	 *
	 * @param array<string,array<string,mixed>> $records Records.
	 * @return array<string,array<string,mixed>>
	 */
	public function prune( array $records ): array {
		$oldest = time() - Controller::PREFLIGHT_HANDOFF_RETENTION_TTL;
		foreach ( $records as $key => $record ) {
			$issued_at = is_array( $record ) ? strtotime( (string) ( $record['issued_at'] ?? '' ) ) : false;
			if ( false === $issued_at || $issued_at < $oldest ) {
				unset( $records[ $key ] );
			}
		}

		if ( count( $records ) <= Controller::MAX_PREFLIGHT_HANDOFFS ) {
			return $records;
		}

		uasort(
			$records,
			static function ( $left, $right ): int {
				$left_time  = is_array( $left ) ? (string) ( $left['issued_at'] ?? '' ) : '';
				$right_time = is_array( $right ) ? (string) ( $right['issued_at'] ?? '' ) : '';

				return strcmp( $left_time, $right_time );
			}
		);

		return array_slice( $records, - Controller::MAX_PREFLIGHT_HANDOFFS, null, true );
	}
	/**
	 * Builds the same input hash Core commit-preflight uses for approved inputs.
	 *
	 * @param array<string,mixed> $proposal Core proposal.
	 * @return string
	 */
	public function input_hash( array $proposal ): string {
		$json = wp_json_encode( $proposal['input'] ?? array() );
		return hash( 'sha256', is_string( $json ) ? $json : '' );
	}
	/**
	 * Returns proposal and target write-action ability ids accepted for a Core handoff.
	 *
	 * @param array<string,mixed> $proposal Core proposal.
	 * @return array<int,string>
	 */
	public function handoff_ability_ids( array $proposal ): array {
		$ability_ids = array(
			sanitize_text_field( (string) ( $proposal['ability_id'] ?? '' ) ),
		);

		$input         = is_array( $proposal['input'] ?? null ) ? $proposal['input'] : array();
		$write_actions = is_array( $input['write_actions'] ?? null ) ? $input['write_actions'] : array();
		foreach ( $write_actions as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$ability_ids[] = sanitize_text_field( (string) ( $action['target_ability_id'] ?? ( $action['ability_id'] ?? '' ) ) );
		}

		return array_values( array_unique( array_filter( $ability_ids ) ) );
	}
	/**
	 * Validates optional Core site/blog binding fields when present.
	 *
	 * @param array<string,mixed> $context Core-provided context.
	 * @param string              $code_prefix Error code prefix.
	 * @param int                 $status HTTP status.
	 * @return true|WP_Error
	 */
	public function validate_context_site_binding( array $context, string $code_prefix, int $status ) {
		// Error families include npcink_openclaw_adapter_preflight_site_url_mismatch, npcink_openclaw_adapter_preflight_handoff_blog_id_mismatch, npcink_openclaw_adapter_core_read_grant_site_url_mismatch, and npcink_openclaw_adapter_core_read_grant_blog_id_mismatch.
		$site_url = sanitize_text_field( (string) ( $context['site_url'] ?? '' ) );
		if ( '' !== $site_url && untrailingslashit( $site_url ) !== untrailingslashit( site_url() ) ) {
			return new WP_Error(
				$code_prefix . '_site_url_mismatch',
				__( 'Core authorization context was issued for a different site URL.', 'npcink-ai-client-adapter' ),
				array(
					'status'        => $status,
					'expected_site' => untrailingslashit( site_url() ),
					'context_site'  => untrailingslashit( $site_url ),
				)
			);
		}

		$home_url = sanitize_text_field( (string) ( $context['home_url'] ?? '' ) );
		if ( '' !== $home_url && untrailingslashit( $home_url ) !== untrailingslashit( home_url() ) ) {
			return new WP_Error(
				$code_prefix . '_home_url_mismatch',
				__( 'Core authorization context was issued for a different home URL.', 'npcink-ai-client-adapter' ),
				array(
					'status'        => $status,
					'expected_home' => untrailingslashit( home_url() ),
					'context_home'  => untrailingslashit( $home_url ),
				)
			);
		}

		$blog_id = absint( $context['blog_id'] ?? 0 );
		if ( $blog_id > 0 && get_current_blog_id() !== $blog_id ) {
			return new WP_Error(
				$code_prefix . '_blog_id_mismatch',
				__( 'Core authorization context was issued for a different blog id.', 'npcink-ai-client-adapter' ),
				array(
					'status'           => $status,
					'expected_blog_id' => get_current_blog_id(),
					'context_blog_id'  => $blog_id,
				)
			);
		}

		return true;
	}
	/**
	 * Validates the Core-issued handoff TTL.
	 *
	 * @param array<string,mixed> $context Core-provided context.
	 * @param string              $code_prefix Error code prefix.
	 * @param int                 $status HTTP status.
	 * @return true|WP_Error
	 */
	public function validate_context_expiry( array $context, string $code_prefix, int $status ) {
		// Error families include npcink_openclaw_adapter_preflight_expired and npcink_openclaw_adapter_preflight_handoff_expired.
		$expires_at = sanitize_text_field( (string) ( $context['expires_at'] ?? '' ) );
		$expires_ts = '' === $expires_at ? false : strtotime( $expires_at );
		if ( false === $expires_ts || $expires_ts <= time() ) {
			return new WP_Error(
				$code_prefix . '_expired',
				__( 'Core authorization context is expired or missing a valid expiry.', 'npcink-ai-client-adapter' ),
				array(
					'status'     => $status,
					'expires_at' => $expires_at,
				)
			);
		}

		return true;
	}
	/**
	 * Validates optional Core signed-client binding fields when present.
	 *
	 * @param array<string,mixed> $context Core-provided context.
	 * @param string              $code_prefix Error code prefix.
	 * @param int                 $status HTTP status.
	 * @return true|WP_Error
	 */
	public function validate_context_signed_client( array $context, string $code_prefix, int $status ) {
		// Error families include npcink_openclaw_adapter_preflight_signed_client_fingerprint_mismatch, npcink_openclaw_adapter_preflight_handoff_signed_client_fingerprint_mismatch, and npcink_openclaw_adapter_core_read_grant_signed_client_fingerprint_mismatch.
		$primary_raw = sanitize_text_field( (string) ( $context['signed_client_fingerprint'] ?? '' ) );
		$alias_raw   = sanitize_text_field( (string) ( $context['client_key_fingerprint'] ?? '' ) );
		$primary     = $this->signing_auth->sanitize_signed_client_fingerprint( $primary_raw );
		$alias       = $this->signing_auth->sanitize_signed_client_fingerprint( $alias_raw );

		if ( ( '' !== $primary_raw && '' === $primary ) || ( '' !== $alias_raw && '' === $alias ) ) {
			return new WP_Error(
				$code_prefix . '_signed_client_fingerprint_invalid',
				__( 'Core authorization context includes an invalid signed client fingerprint.', 'npcink-ai-client-adapter' ),
				array( 'status' => $status )
			);
		}

		if ( '' !== $primary && '' !== $alias && ! hash_equals( $primary, $alias ) ) {
			return new WP_Error(
				$code_prefix . '_signed_client_fingerprint_alias_mismatch',
				__( 'Core authorization context signed client fingerprint aliases do not match.', 'npcink-ai-client-adapter' ),
				array( 'status' => $status )
			);
		}

		$context_fingerprint = '' !== $primary ? $primary : $alias;
		if ( '' === $context_fingerprint ) {
			return true;
		}

		$current_fingerprint = (string) call_user_func( $this->fingerprint_provider );
		if ( '' === $current_fingerprint || ! hash_equals( $current_fingerprint, $context_fingerprint ) ) {
			return new WP_Error(
				$code_prefix . '_signed_client_fingerprint_mismatch',
				__( 'Core authorization context was issued for a different signed local client.', 'npcink-ai-client-adapter' ),
				array(
					'status'                      => $status,
					'expected_client_fingerprint' => $current_fingerprint,
					'context_client_fingerprint'  => $context_fingerprint,
				)
			);
		}

		return true;
	}
	/**
	 * Verifies Core issued an Adapter-owned execution handoff for this proposal.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param array<string,mixed> $proposal Core proposal.
	 * @param array<string,mixed> $preflight Core preflight payload.
	 * @param array<string,mixed> $approval_context Core approval context.
	 * @param array<string,mixed> $execution_handoff Core execution handoff.
	 * @return true|WP_Error
	 */
	public function validate_execution_handoff( string $proposal_id, array $proposal, array $preflight, array $approval_context, array $execution_handoff ) {
		if ( empty( $execution_handoff ) ) {
			return new WP_Error(
				'npcink_openclaw_adapter_preflight_handoff_missing',
				__( 'Core commit preflight did not return an Adapter execution handoff.', 'npcink-ai-client-adapter' ),
				array(
					'status'           => 409,
					'proposal_id'      => $proposal_id,
					'commit_execution' => false,
				)
			);
		}

		$executor = sanitize_key( (string) ( $execution_handoff['executor'] ?? '' ) );
		if ( 'adapter_after_core_preflight' !== $executor ) {
			return new WP_Error(
				'npcink_openclaw_adapter_preflight_handoff_executor_invalid',
				__( 'Core execution handoff was not issued for Adapter after Core preflight.', 'npcink-ai-client-adapter' ),
				array(
					'status'            => 409,
					'proposal_id'       => $proposal_id,
					'executor'          => $executor,
					'expected_executor' => 'adapter_after_core_preflight',
					'commit_execution'  => false,
				)
			);
		}

		$execution_surface = sanitize_key( (string) ( $execution_handoff['execution_surface'] ?? '' ) );
		if ( 'wp_abilities_rest' !== $execution_surface ) {
			return new WP_Error(
				'npcink_openclaw_adapter_preflight_handoff_execution_surface_invalid',
				__( 'Core execution handoff was not issued for the WordPress Abilities REST surface.', 'npcink-ai-client-adapter' ),
				array(
					'status'                     => 409,
					'proposal_id'                => $proposal_id,
					'execution_surface'          => $execution_surface,
					'expected_execution_surface' => 'wp_abilities_rest',
					'commit_execution'           => false,
				)
			);
		}

		if ( false !== (bool) ( $execution_handoff['core_proxy_execute'] ?? true ) ) {
			return new WP_Error(
				'npcink_openclaw_adapter_preflight_handoff_core_proxy_execute_unsupported',
				__( 'Core execution handoff must keep core_proxy_execute=false for Adapter execution.', 'npcink-ai-client-adapter' ),
				array(
					'status'             => 409,
					'proposal_id'        => $proposal_id,
					'core_proxy_execute' => (bool) ( $execution_handoff['core_proxy_execute'] ?? true ),
					'commit_execution'   => false,
				)
			);
		}

		if ( false !== (bool) ( $execution_handoff['commit_execution'] ?? true ) ) {
			return new WP_Error(
				'npcink_openclaw_adapter_preflight_handoff_commit_execution_unsupported',
				__( 'Core execution handoff must keep commit_execution=false before Adapter execution.', 'npcink-ai-client-adapter' ),
				array(
					'status'           => 409,
					'proposal_id'      => $proposal_id,
					'commit_execution' => (bool) ( $execution_handoff['commit_execution'] ?? true ),
				)
			);
		}

		$handoff_proposal_id = sanitize_text_field( (string) ( $execution_handoff['proposal_id'] ?? '' ) );
		if ( $proposal_id !== $handoff_proposal_id ) {
			return new WP_Error(
				'npcink_openclaw_adapter_preflight_handoff_proposal_mismatch',
				__( 'Core execution handoff proposal id does not match the Adapter execution request.', 'npcink-ai-client-adapter' ),
				array(
					'status'              => 409,
					'proposal_id'         => $proposal_id,
					'handoff_proposal_id' => $handoff_proposal_id,
					'commit_execution'    => false,
				)
			);
		}

		$handoff_ability_id  = sanitize_text_field( (string) ( $execution_handoff['ability_id'] ?? '' ) );
		$allowed_ability_ids = $this->handoff_ability_ids( $proposal );
		if ( '' === $handoff_ability_id || ! in_array( $handoff_ability_id, $allowed_ability_ids, true ) ) {
			return new WP_Error(
				'npcink_openclaw_adapter_preflight_handoff_ability_mismatch',
				__( 'Core execution handoff ability id does not match the approved proposal or write actions.', 'npcink-ai-client-adapter' ),
				array(
					'status'              => 409,
					'proposal_id'         => $proposal_id,
					'handoff_ability_id'  => $handoff_ability_id,
					'allowed_ability_ids' => $allowed_ability_ids,
					'commit_execution'    => false,
				)
			);
		}

		$handoff_correlation_id = sanitize_text_field( (string) ( $execution_handoff['correlation_id'] ?? '' ) );
		$correlation_id         = sanitize_text_field( (string) ( $preflight['correlation_id'] ?? ( $approval_context['correlation_id'] ?? '' ) ) );
		if ( '' === $handoff_correlation_id || '' === $correlation_id || $handoff_correlation_id !== $correlation_id ) {
			return new WP_Error(
				'npcink_openclaw_adapter_preflight_handoff_correlation_mismatch',
				__( 'Core execution handoff correlation id does not match the commit preflight correlation id.', 'npcink-ai-client-adapter' ),
				array(
					'status'                 => 409,
					'proposal_id'            => $proposal_id,
					'correlation_id'         => $correlation_id,
					'handoff_correlation_id' => $handoff_correlation_id,
					'commit_execution'       => false,
				)
			);
		}

		return true;
	}
	/**
	 * Verifies Core preflight still binds to the approved proposal input and policy.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param array<string,mixed> $proposal Core proposal.
	 * @param array<string,mixed> $preflight Core preflight payload.
	 * @return true|WP_Error
	 */
	public function validate_binding( string $proposal_id, array $proposal, array $preflight ) {
		$approval_context  = is_array( $preflight['approval_context'] ?? null ) ? $preflight['approval_context'] : array();
		$execution_handoff = is_array( $preflight['execution_handoff'] ?? null ) ? $preflight['execution_handoff'] : array();
		$current_hash      = $this->input_hash( $proposal );
		$approved_hash     = sanitize_text_field( (string) ( $approval_context['approved_input_hash'] ?? '' ) );
		$handoff_hash      = sanitize_text_field( (string) ( $execution_handoff['approved_input_hash'] ?? $approved_hash ) );

		if ( '' === $approved_hash || $approved_hash !== $current_hash || $handoff_hash !== $approved_hash ) {
			return new WP_Error(
				'npcink_openclaw_adapter_preflight_input_hash_mismatch',
				__( 'Core commit preflight approved input hash does not match the current proposal input.', 'npcink-ai-client-adapter' ),
				array(
					'status'              => 409,
					'proposal_id'         => $proposal_id,
					'approved_input_hash' => $approved_hash,
					'current_input_hash'  => $current_hash,
					'handoff_input_hash'  => $handoff_hash,
					'commit_execution'    => false,
				)
			);
		}

		$policy_version = sanitize_key( (string) ( $approval_context['policy_version'] ?? '' ) );
		$handoff_policy = sanitize_key( (string) ( $execution_handoff['policy_version'] ?? $policy_version ) );
		if ( 'core-preflight-v1' !== $policy_version || 'core-preflight-v1' !== $handoff_policy ) {
			return new WP_Error(
				'npcink_openclaw_adapter_preflight_policy_version_invalid',
				__( 'Core commit preflight policy version is not accepted by Adapter execution.', 'npcink-ai-client-adapter' ),
				array(
					'status'                  => 409,
					'proposal_id'             => $proposal_id,
					'policy_version'          => $policy_version,
					'handoff_policy_version'  => $handoff_policy,
					'accepted_policy_version' => 'core-preflight-v1',
					'commit_execution'        => false,
				)
			);
		}

		$handoff_binding = $this->validate_execution_handoff( $proposal_id, $proposal, $preflight, $approval_context, $execution_handoff );
		if ( is_wp_error( $handoff_binding ) ) {
			return $handoff_binding;
		}

		$approval_site_binding = $this->validate_context_site_binding( $approval_context, 'npcink_openclaw_adapter_preflight', 409 );
		if ( is_wp_error( $approval_site_binding ) ) {
			return $approval_site_binding;
		}
		$handoff_site_binding = $this->validate_context_site_binding( $execution_handoff, 'npcink_openclaw_adapter_preflight_handoff', 409 );
		if ( is_wp_error( $handoff_site_binding ) ) {
			return $handoff_site_binding;
		}
		$approval_expiry = $this->validate_context_expiry( $approval_context, 'npcink_openclaw_adapter_preflight', 409 );
		if ( is_wp_error( $approval_expiry ) ) {
			return $approval_expiry;
		}
		$handoff_expiry = $this->validate_context_expiry( $execution_handoff, 'npcink_openclaw_adapter_preflight_handoff', 409 );
		if ( is_wp_error( $handoff_expiry ) ) {
			return $handoff_expiry;
		}
		$approval_client_binding = $this->validate_context_signed_client( $approval_context, 'npcink_openclaw_adapter_preflight', 409 );
		if ( is_wp_error( $approval_client_binding ) ) {
			return $approval_client_binding;
		}
		$handoff_client_binding = $this->validate_context_signed_client( $execution_handoff, 'npcink_openclaw_adapter_preflight_handoff', 409 );
		if ( is_wp_error( $handoff_client_binding ) ) {
			return $handoff_client_binding;
		}

		return true;
	}
	/**
	 * Stores a Core preflight handoff for the next Adapter execute call.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param array<string,mixed> $proposal Core proposal.
	 * @param array<string,mixed> $preflight Core preflight payload.
	 * @return array<string,mixed>|null
	 */
	public function store( string $proposal_id, array $proposal, array $preflight ): ?array {
		if ( '' === $proposal_id || empty( $proposal ) ) {
			return null;
		}

		$approval_context = is_array( $preflight['approval_context'] ?? null ) ? $preflight['approval_context'] : array();
		$approved_hash    = sanitize_text_field( (string) ( $approval_context['approved_input_hash'] ?? ( $preflight['approved_input_hash'] ?? '' ) ) );
		$current_hash     = $this->input_hash( $proposal );
		$policy_version   = sanitize_key( (string) ( $approval_context['policy_version'] ?? ( $preflight['policy_version'] ?? '' ) ) );
		if (
			true !== (bool) ( $approval_context['approval_commit_authorized'] ?? false )
			|| false !== (bool) ( $preflight['commit_execution'] ?? true )
			|| (string) ( $approval_context['proposal_id'] ?? $proposal_id ) !== $proposal_id
			|| '' === $approved_hash
			|| $approved_hash !== $current_hash
			|| 'core-preflight-v1' !== $policy_version
		) {
			return null;
		}

		$binding = $this->validate_binding( $proposal_id, $proposal, $preflight );
		if ( is_wp_error( $binding ) ) {
			return null;
		}

		$handoff = array(
			'status'              => 'issued',
			'proposal_id'         => $proposal_id,
			'ability_id'          => sanitize_text_field( (string) ( $proposal['ability_id'] ?? ( $approval_context['ability_id'] ?? '' ) ) ),
			'approved_input_hash' => $approved_hash,
			'correlation_id'      => sanitize_text_field( (string) ( $preflight['correlation_id'] ?? ( $approval_context['correlation_id'] ?? '' ) ) ),
			'commit_execution'    => false,
			'issued_at'           => gmdate( 'c' ),
			'preflight'           => array(
				'proposal_id'             => $proposal_id,
				'correlation_id'          => sanitize_text_field( (string) ( $preflight['correlation_id'] ?? ( $approval_context['correlation_id'] ?? '' ) ) ),
				'approval_context'        => $approval_context,
				'capability'              => is_array( $preflight['capability'] ?? null ) ? $preflight['capability'] : array(),
				'contract_preflight'      => is_array( $preflight['contract_preflight'] ?? null ) ? $preflight['contract_preflight'] : array(),
				'proposal_item_preflight' => is_array( $preflight['proposal_item_preflight'] ?? null ) ? $preflight['proposal_item_preflight'] : array(),
				'execution_handoff'       => is_array( $preflight['execution_handoff'] ?? null ) ? $preflight['execution_handoff'] : array(),
				'idempotency_required'    => (bool) ( $preflight['idempotency_required'] ?? true ),
				'commit_execution'        => false,
			),
		);

		$records = $this->handoffs();
		$records[ $this->execution_records->record_key( $proposal_id ) ] = $handoff;
		$records = $this->prune( $records );
		update_option( Controller::PREFLIGHT_HANDOFFS_OPTION, $records, false );

		return $handoff;
	}
	/**
	 * Consumes a cached preflight handoff when it still matches the proposal.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param array<string,mixed> $proposal Core proposal.
	 * @return array<string,mixed>|null
	 */
	public function consume( string $proposal_id, array $proposal ): ?array {
		$records = $this->handoffs();
		$key     = $this->execution_records->record_key( $proposal_id );
		$record  = is_array( $records[ $key ] ?? null ) ? $records[ $key ] : array();
		if ( empty( $record ) ) {
			return null;
		}

		unset( $records[ $key ] );
		update_option( Controller::PREFLIGHT_HANDOFFS_OPTION, $records, false );

		$preflight        = is_array( $record['preflight'] ?? null ) ? $record['preflight'] : array();
		$approval_context = is_array( $preflight['approval_context'] ?? null ) ? $preflight['approval_context'] : array();
		$approved_hash    = sanitize_text_field( (string) ( $record['approved_input_hash'] ?? ( $approval_context['approved_input_hash'] ?? '' ) ) );
		$policy_version   = sanitize_key( (string) ( $approval_context['policy_version'] ?? ( $preflight['policy_version'] ?? '' ) ) );
		if (
			'issued' !== (string) ( $record['status'] ?? '' )
			|| (string) ( $record['proposal_id'] ?? '' ) !== $proposal_id
			|| (string) ( $approval_context['proposal_id'] ?? $proposal_id ) !== $proposal_id
			|| true !== (bool) ( $approval_context['approval_commit_authorized'] ?? false )
			|| false !== (bool) ( $preflight['commit_execution'] ?? true )
			|| '' === $approved_hash
			|| $approved_hash !== $this->input_hash( $proposal )
			|| 'core-preflight-v1' !== $policy_version
		) {
			return null;
		}

		$binding = $this->validate_binding( $proposal_id, $proposal, $preflight );
		if ( is_wp_error( $binding ) ) {
			return null;
		}

		return $preflight;
	}
	/**
	 * Returns a cached Adapter preflight handoff without consuming it.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param array<string,mixed> $proposal Core proposal payload.
	 * @return array<string,mixed>|null
	 */
	public function cached_for_status( string $proposal_id, array $proposal ): ?array {
		$records = $this->handoffs();
		$record  = is_array( $records[ $this->execution_records->record_key( $proposal_id ) ] ?? null ) ? $records[ $this->execution_records->record_key( $proposal_id ) ] : array();
		if ( empty( $record ) || 'issued' !== (string) ( $record['status'] ?? '' ) ) {
			return null;
		}

		$approved_hash = sanitize_text_field( (string) ( $record['approved_input_hash'] ?? '' ) );
		if ( '' === $approved_hash || $approved_hash !== $this->input_hash( $proposal ) ) {
			return null;
		}

		return $record;
	}
}
