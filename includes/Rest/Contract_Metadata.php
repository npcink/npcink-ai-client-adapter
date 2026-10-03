<?php
/**
 * Pure builders for Adapter machine-readable contract metadata.
 *
 * Every method here is a static, side-effect-free value builder: no request
 * state, no upstream dispatch, no storage. Request-scoped classification
 * (signed-auth boundary state) and private Controller registry data are
 * passed in by the Controller delegators, so the emitted contract values
 * stay byte-identical to the pre-extraction payloads.
 *
 * @package NpcinkOpenClawAdapter
 */

namespace Npcink\OpenClawAdapter\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds contract, policy, and posture metadata payloads.
 */
final class Contract_Metadata {
	/**
	 * Returns the generic AI-client projection contract for Toolkit workflows.
	 *
	 * This is discovery metadata only. Adapter does not copy or persist workflow
	 * definitions and does not become a workflow registry or runtime.
	 *
	 * @return array<string,mixed>
	 */
	public static function workflow_projection_contract(): array {
		return array(
			'schema_version'                => 'npcink_ai_client_workflow_projection.v1',
			'definition_owner'              => 'npcink-abilities-toolkit',
			'definition_discovery_surface'  => 'wordpress_abilities_api_via_adapter_read',
			'definition_discovery_contract' => 'toolkit_workflow_definition_abilities',
			'projection_role'               => 'external_ai_client_channel',
			'supported_channels'            => array( 'openclaw' ),
			'canonical_definition_storage'  => false,
			'runtime_state_storage'         => false,
			'version_mismatch_policy'       => 'fail_closed',
			'parity_required_fields'        => array(
				'recipe_id',
				'contract_version',
				'entrypoint_ability_id',
				'required_scope',
				'required_inputs',
				'handoff',
				'failure_policy',
				'host_governed_write_boundary',
			),
		);
	}
	/**
	 * Returns the stable Adapter/Core final-write handoff posture.
	 *
	 * @return array<string,mixed>
	 */
	public static function execution_handoff_posture(): array {
		return array(
			'schema_version'           => 'npcink_openclaw_adapter_execution_handoff_posture.v1',
			'channel_owner'            => 'npcink-ai-client-adapter',
			'governance_truth_owner'   => 'npcink-governance-core',
			'ability_definition_owner' => 'npcink-abilities-toolkit',
			'approval_truth'           => 'npcink_governance_core',
			'commit_preflight_truth'   => 'npcink_governance_core',
			'execution_owner'          => 'adapter_after_core_preflight',
			'execution_surface'        => 'wp_abilities_rest',
			'record_execution_route'   => '/npcink-governance-core/v1/proposals/{proposal_id}/record-execution',
			'core_proxy_execute'       => false,
			'commit_execution'         => false,
			'generic_write_executor'   => false,
			'workflow_runtime'         => false,
			'queue_or_scheduler'       => false,
			'required_evidence'        => array(
				'approval_context.approval_commit_authorized',
				'approval_context.approved_input_hash',
				'approval_context.policy_version=core-preflight-v1',
				'execution_handoff.executor=adapter_after_core_preflight',
				'execution_handoff.execution_surface=wp_abilities_rest',
				'execution_handoff.core_proxy_execute=false',
				'execution_handoff.commit_execution=false',
				'execution_handoff.correlation_id',
				'implementation_posture.checked_or_not_declared',
			),
			'operator_block_guidance'  => 'surface_operator_feedback_and_create_revised_proposal',
		);
	}
	/**
	 * Returns non-secret site readiness for conditional execution profiles.
	 *
	 * Target names stay private. A configured host filter still requires a
	 * per-target check before final execution.
	 *
	 * @return array<string,mixed>
	 */
	public static function execution_profile_readiness( array $execution_profiles, array $conditional_execute_ability_ids ): array {
		$items = array();
		foreach ( $execution_profiles as $ability_id => $profile ) {
			$policy = is_array( $profile['site_readiness'] ?? null ) ? $profile['site_readiness'] : array();
			if ( empty( $policy ) ) {
				continue;
			}

			$filter                        = sanitize_key( (string) ( $policy['filter'] ?? '' ) );
			$configured                    = '' !== $filter && function_exists( 'has_filter' ) && false !== has_filter( $filter );
			$items[ (string) $ability_id ] = array(
				'status'                    => $configured ? 'target_dependent' : 'not_configured',
				'site_policy_configured'    => $configured,
				'per_target_check_required' => true,
				'default_behavior'          => 'fail_closed',
				'policy_owner'              => sanitize_key( (string) ( $policy['policy_owner'] ?? 'wordpress_host' ) ),
				'filter'                    => $filter,
				'not_ready_code'            => sanitize_key( (string) ( $policy['not_ready_code'] ?? 'npcink_openclaw_adapter_execution_profile_site_not_ready' ) ),
				'target_names_exposed'      => false,
			);
		}

		return array(
			'schema_version'                  => 'npcink_openclaw_adapter_execution_profile_readiness.v1',
			'conditional_execute_ability_ids' => $conditional_execute_ability_ids,
			'items'                           => $items,
		);
	}
	/**
	 * Returns machine-readable Adapter contract metadata for clients.
	 *
	 * @return array<string,mixed>
	 */
	public static function adapter_contract_metadata( array $execution_profiles, array $execute_ability_ids, array $plan_ability_ids ): array {
		return array(
			'schema_version'                     => 'npcink_openclaw_adapter_contract.v1',
			'adapter_contract_version'           => Controller::ADAPTER_CONTRACT_VERSION,
			'product_name'                       => 'npcink-ai-client-adapter',
			'client_contract'                    => 'generic_ai_client',
			'priority_channel'                   => 'openclaw',
			'compatibility_rest_namespace'       => Controller::NAMESPACE,
			'client_policy_version'              => Controller::CLIENT_POLICY_VERSION,
			'execution_profile_registry_version' => Controller::EXECUTION_PROFILE_REGISTRY_VERSION,
			'supported_plan_abilities_version'   => Controller::SUPPORTED_PLAN_ABILITIES_VERSION,
			'core_contract_min_version'          => Controller::CORE_CONTRACT_MIN_VERSION,
			'core_plugin_min_version'            => Controller::CORE_PLUGIN_MIN_VERSION,
			'toolkit_contract_min_version'       => Controller::TOOLKIT_CONTRACT_MIN_VERSION,
			'toolkit_plugin_min_version'         => Controller::TOOLKIT_PLUGIN_MIN_VERSION,
			'execution_profile_registry_hash'    => self::contract_sha256( $execution_profiles ),
			'supported_execute_ability_ids_hash' => self::contract_sha256( $execute_ability_ids ),
			'supported_plan_ability_ids_hash'    => self::contract_sha256( $plan_ability_ids ),
			'max_execution_actions'              => Controller::MAX_EXECUTION_ACTIONS,
			'core_proxy_execute'                 => false,
			'commit_execution'                   => false,
			'workflow_projection'                => self::workflow_projection_contract(),
			'execution_handoff_posture'          => self::execution_handoff_posture(),
		);
	}
	/**
	 * Returns machine-readable client policy for local AI clients.
	 *
	 * @param bool $include_request_scoped_policy Whether to embed the per-request boundary enforcement classification. Exclude it from digest bases such as the connection manifest.
	 * @return array<string,mixed>
	 */
	public static function client_policy( bool $include_request_scoped_policy, bool $current_signed_authenticated ): array {
		$policy = array(
			'schema_version'         => 'npcink_openclaw_adapter_client_policy.v1',
			'policy_version'         => Controller::CLIENT_POLICY_VERSION,
			'policy_owner'           => 'npcink-ai-client-adapter',
			'client_posture'         => 'adapter_only_fail_closed',
			'forbidden_outputs'      => array(
				'profile_path',
				'profile_json',
				'private_key',
				'private_key_jwk',
				'public_key',
				'key_id',
				'connection_id',
				'authorization',
				'cookie',
				'token',
				'application_password',
				'password',
				'secret',
				'signature',
				'x_npcink_key_id',
				'x_npcink_signature',
			),
			'forbidden_local_access' => array(
				'keypair_profile_files',
				'database_direct',
				'filesystem_reads_for_wordpress_data',
				'log_file_reads',
				'custom_scripts_for_wordpress_data',
				'direct_wordpress_internals',
			),
			'allowed_transport'      => array(
				'adapter_cli_only'               => true,
				'adapter_relative_routes_only'   => true,
				'direct_database_access_allowed' => false,
				'filesystem_secret_read_allowed' => false,
			),
			'sensitive_read_flow'    => array(
				'required'              => true,
				'trigger_fields'        => array(
					'read_authorization_required=true',
					'requires_read_authorization=true',
					'read_policy=core_read_authorization_required',
					'governance_mode=core_read_authorization_required',
					'authorization_mode=core_read_request',
				),
				'steps'                 => array(
					'create'  => 'POST /read-requests',
					'status'  => 'GET /read-requests/{request_id}',
					'execute' => 'POST /run-read-ability with identical ability_id, input, and read_request_id',
				),
				'grant_binding'         => 'ability_id_plus_input_hash',
				'input_change_behavior' => 'create_new_read_request',
			),
			'write_flow'             => array(
				'required'                    => true,
				'proposal_required'           => true,
				'approval_surface'            => 'npcink_governance_core_admin',
				'signed_client_self_approval' => 'forbidden',
				'commit_intent_required'      => true,
				'execution_handoff_posture'   => self::execution_handoff_posture(),
				'final_write_routes'          => array(
					'POST /execute-approved-proposal',
					'POST /proposals/{proposal_id}/execute',
				),
				'admin_session_only_routes'   => array(
					'POST /proposals/{proposal_id}/approve-and-execute' => 'unified approve-and-execute holds approval authority and requires a WordPress administrator session',
				),
			),
			'recommended_cli'        => array(
				'status'              => 'npcink-openclaw-adapter status --profile=local',
				'read_request_create' => 'npcink-openclaw-adapter read-request create --profile=local --ability-id=ABILITY_ID --input-file=/tmp/input.json --purpose=PURPOSE --data-classes=CLASS[,CLASS]',
				'read_request_status' => 'npcink-openclaw-adapter read-request status --profile=local REQUEST_ID',
				'read_ability'        => 'npcink-openclaw-adapter read-ability --profile=local --ability-id=ABILITY_ID --input-file=/tmp/input.json [--read-request-id=REQUEST_ID]',
			),
		);

		if ( $include_request_scoped_policy ) {
			$policy['boundary_enforcement'] = self::boundary_enforcement_policy( $current_signed_authenticated );
		}

		return $policy;
	}
	/**
	 * Returns the boundary enforcement classification of the active connection.
	 *
	 * Semantics are owned by docs/threat-model.md: enforced means the request
	 * was authenticated by a registered Ed25519 key-pair signature, so Adapter
	 * routes are the only reachable WordPress path for that client;
	 * conventional means a WordPress-native credential that can also reach
	 * wp/v2 directly, which makes the approval gate voluntary.
	 *
	 * @return array<string,string>
	 */
	public static function boundary_enforcement_policy( bool $current_signed_authenticated ): array {
		$enforced = $current_signed_authenticated;

		return array(
			'class'       => $enforced ? 'enforced' : 'conventional',
			'auth_mode'   => $enforced ? 'ed25519_key_pair_signed' : 'wordpress_native',
			'recommended' => 'ed25519_key_pair_device_pairing',
			'reference'   => 'docs/threat-model.md',
		);
	}
	/**
	 * Returns scheme/host/port origin for a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function url_origin( string $url ): string {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );
		$port   = wp_parse_url( $url, PHP_URL_PORT );
		if ( ! is_string( $scheme ) || ! is_string( $host ) ) {
			return '';
		}

		return strtolower( $scheme . '://' . $host . ( is_int( $port ) ? ':' . $port : '' ) );
	}
	/**
	 * Returns canonical JSON for digesting simple associative arrays.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function canonical_json( $value ): string {
		$value = self::sort_array_keys_recursive( $value );
		$json  = wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? $json : '';
	}
	/**
	 * Returns a stable sha256 digest for machine-readable contract data.
	 *
	 * @param mixed $value Contract value.
	 * @return string
	 */
	public static function contract_sha256( $value ): string {
		return 'sha256:' . hash( 'sha256', self::canonical_json( self::contract_hash_value( $value ) ) );
	}
	/**
	 * Removes human-translated strings from contract hash input.
	 *
	 * @param mixed $value Contract value.
	 * @return mixed
	 */
	public static function contract_hash_value( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$filtered = array();
		foreach ( $value as $key => $child ) {
			if ( 'message' === $key ) {
				continue;
			}
			$filtered[ $key ] = self::contract_hash_value( $child );
		}

		return $filtered;
	}
	/**
	 * Sorts associative array keys recursively.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function sort_array_keys_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $child ) {
			$value[ $key ] = self::sort_array_keys_recursive( $child );
		}

		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}

		return $value;
	}
}
