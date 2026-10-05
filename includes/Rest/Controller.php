<?php
/**
 * Adapter REST controller.
 *
 * @package NpcinkOpenClawAdapter
 */

namespace Npcink\OpenClawAdapter\Rest;

use Npcink\OpenClawAdapter\Observability;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes a thin OpenClaw adapter surface.
 */
final class Controller {
	const NAMESPACE                            = 'npcink-openclaw-adapter/v1';
	const MAX_EXECUTION_ACTIONS                = 200;
	const DEVICE_PAIRING_OPTION                = 'npcink_openclaw_adapter_device_pairings';
	const CLIENT_KEYS_OPTION                   = 'npcink_openclaw_adapter_client_keys';
	const EXECUTION_RECORDS_OPTION             = 'npcink_openclaw_adapter_execution_records';
	const PREFLIGHT_HANDOFFS_OPTION            = 'npcink_openclaw_adapter_preflight_handoffs';
	const DEVICE_PAIRING_TTL                   = 600;
	const SIGNATURE_NONCE_TTL                  = 300;
	const SIGNATURE_NONCE_OPTION_PREFIX        = 'npcink_openclaw_adapter_sig_nonce_';
	const SIGNATURE_NONCE_CLEANUP_BATCH        = 100;
	const DEVICE_PAIRING_RATE_LIMIT_TTL        = 60;
	const DEVICE_PAIRING_POLL_RATE_LIMIT_TTL   = 60;
	const MAX_DEVICE_PAIRINGS                  = 100;
	const MAX_DEVICE_PAIRING_STARTS_PER_WINDOW = 20;
	const MAX_DEVICE_PAIRING_POLLS_PER_WINDOW  = 60;
	const MAX_DEVICE_PAIRING_BODY_BYTES        = 8192;
	const MAX_DEVICE_PAIRING_POLL_BODY_BYTES   = 1024;
	const MAX_EXECUTION_RECORDS                = 500;
	const MAX_PREFLIGHT_HANDOFFS               = 500;
	const EXECUTION_LOCK_TTL                   = 300;
	const DISCOVERY_CACHE_TTL                  = 60;
	const PREFLIGHT_HANDOFF_RETENTION_TTL      = 900;
	const EXECUTION_RECORD_RETENTION_TTL       = 604800;
	const MAX_UPSTREAM_ERROR_DETAIL_BYTES      = 8192;
	const CLIENT_KEY_LAST_USED_WRITE_TTL       = 60;
	const MAX_REST_BODY_BYTES                  = 1048576;
	const MAX_PROPOSAL_LIST_LIMIT              = 100;
	const MAX_LIGHT_POST_BODY_BYTES            = 4096;
	const MAX_LOG_CONTEXT_FIELDS               = 32;
	const MAX_LOG_CONTEXT_DEPTH                = 2;
	const MAX_LOG_CONTEXT_STRING_BYTES         = 200;
	const MAX_LOG_CONTEXT_SERIALIZED_BYTES     = 8192;
	const ADAPTER_CONTRACT_VERSION             = '4';
	const CLIENT_POLICY_VERSION                = '2';
	const EXECUTION_PROFILE_REGISTRY_VERSION   = '2';
	const SUPPORTED_PLAN_ABILITIES_VERSION     = '1';
	const CORE_CONTRACT_MIN_VERSION            = '1';
	const CORE_PLUGIN_MIN_VERSION              = '0.1.0';
	const TOOLKIT_CONTRACT_MIN_VERSION         = '1';
	const TOOLKIT_PLUGIN_MIN_VERSION           = '0.5.3';

	/**
	 * Current request log context while an ability is running.
	 *
	 * @var array<string,mixed>
	 */
	private $current_request_log_context = array();

	/**
	 * Current signed local client fingerprint for the REST request.
	 *
	 * @var string
	 */
	private $current_signed_client_fingerprint = '';

	/**
	 * Whether the current request authenticated through a registered Ed25519
	 * key-pair signature. Tracked separately from the fingerprint because a
	 * legacy or hand-edited key record may authenticate while carrying an
	 * empty or invalid fingerprint value.
	 *
	 * @var bool
	 */
	private $current_signed_authenticated = false;

	/**
	 * Request-local dependency contract cache.
	 *
	 * @var array<string,mixed>|null
	 */
	private $dependency_contracts_cache = null;

	/**
	 * Adapter-owned execution input validator.
	 *
	 * @var Execution_Input_Validator
	 */
	private $execution_input_validator;

	/**
	 * Adapter-owned normalized execution runner.
	 *
	 * @var Execution_Action_Runner
	 */
	private $execution_action_runner;

	/**
	 * Ed25519 signing auth and device pairing domain service.
	 *
	 * @var Signing_Auth
	 */
	private $signing_auth;

	/**
	 * Adapter-owned execution record storage service.
	 *
	 * @var Execution_Records
	 */
	private $execution_records;

	/**
	 * Upstream Core/Toolkit REST transport service.
	 *
	 * @var Upstream_Dispatch
	 */
	private $upstream_dispatch;

	/**
	 * Creates the REST controller with the canonical execution profile rules.
	 */
	public function __construct() {
		$this->signing_auth              = new Signing_Auth(
			function ( string $event_kind, float $started, $error, array $context = array() ): void {
				$this->emit_operation_event( $event_kind, $started, $error, $context );
			}
		);
		$this->execution_records         = new Execution_Records();
		$this->upstream_dispatch         = new Upstream_Dispatch(
			$this->signing_auth,
			function ( string $event_kind, float $started, $error, array $context = array() ): void {
				$this->emit_operation_event( $event_kind, $started, $error, $context );
			},
			function ( string $route ) {
				return $this->missing_dependency_for_route( $route );
			},
			function (): string {
				return $this->current_signed_client_fingerprint();
			}
		);
		$this->execution_input_validator = new Execution_Input_Validator( self::execution_profiles() );
		$this->execution_action_runner   = new Execution_Action_Runner(
			self::execution_profiles(),
			function ( array $context, string $method, string $route, array $params, bool $query_params, bool $json_body ) {
				return $this->dispatch_upstream_with_runtime_context( $context, $method, $route, $params, $query_params, $json_body );
			},
			function ( string $ability_id, array $ability_input, array $ability_result, array $base_request_context ): array {
				return $this->block_write_readback_verification( $ability_id, $ability_input, $ability_result, $base_request_context );
			}
		);
	}

	/**
	 * Returns Adapter-owned execution profiles for abilities that may run after
	 * Core approval and commit preflight.
	 *
	 * Discovery tells Adapter which abilities exist; this registry is the
	 * explicit opt-in policy for final WordPress writes.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function execution_profiles(): array {
		return Execution_Profile_Registry::profiles();
	}

	/**
	 * Returns ability ids this adapter may execute after Core approval.
	 *
	 * @return array<int,string>
	 */
	private static function supported_execute_ability_ids(): array {
		return array_keys( self::execution_profiles() );
	}

	/**
	 * Returns execution profiles whose final readiness depends on host policy.
	 *
	 * @return array<int,string>
	 */
	private static function conditional_execute_ability_ids(): array {
		$ability_ids = array();
		foreach ( self::execution_profiles() as $ability_id => $profile ) {
			if ( is_array( $profile['site_readiness'] ?? null ) ) {
				$ability_ids[] = (string) $ability_id;
			}
		}

		return $ability_ids;
	}


	/**
	 * Returns detected Core and Toolkit runtime contract summaries.
	 *
	 * @return array<string,mixed>
	 */
	/**
	 * Returns conditional execution profile readiness metadata.
	 *
	 * @return array<string,mixed>
	 */
	private function execution_profile_readiness(): array {
		return Contract_Metadata::execution_profile_readiness( self::execution_profiles(), self::conditional_execute_ability_ids() );
	}

	/**
	 * Returns machine-readable Adapter contract metadata for clients.
	 *
	 * @return array<string,mixed>
	 */
	private function adapter_contract_metadata(): array {
		return Contract_Metadata::adapter_contract_metadata( self::execution_profiles(), self::supported_execute_ability_ids(), Supported_Plan_Abilities::ids() );
	}

	/**
	 * Returns the stable Adapter/Core final-write handoff posture.
	 *
	 * @return array<string,mixed>
	 */
	private function execution_handoff_posture(): array {
		return Contract_Metadata::execution_handoff_posture();
	}

	/**
	 * Returns machine-readable client policy for local AI clients.
	 *
	 * @param bool $include_request_scoped_policy Whether to embed the per-request boundary enforcement classification.
	 * @return array<string,mixed>
	 */
	private function client_policy( bool $include_request_scoped_policy = true ): array {
		return Contract_Metadata::client_policy( $include_request_scoped_policy, $this->current_signed_authenticated );
	}

	/**
	 * Returns the boundary enforcement classification of the active connection.
	 *
	 * @return array<string,string>
	 */
	private function boundary_enforcement_policy(): array {
		return Contract_Metadata::boundary_enforcement_policy( $this->current_signed_authenticated );
	}

	/**
	 * Returns scheme/host/port origin for a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function url_origin( string $url ): string {
		return Contract_Metadata::url_origin( $url );
	}

	/**
	 * Returns canonical JSON for digesting simple associative arrays.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function canonical_json( $value ): string {
		return Contract_Metadata::canonical_json( $value );
	}

	private function dependency_contracts(): array {
		if ( is_array( $this->dependency_contracts_cache ) ) {
			return $this->dependency_contracts_cache;
		}

		$cached = get_transient( 'npcink_openclaw_adapter_dependency_contracts_v1' );
		if ( is_array( $cached ) ) {
			$this->dependency_contracts_cache = $cached;
			return $cached;
		}

		$core    = $this->dependency_contract_summary(
			'npcink-governance-core',
			'/npcink-governance-core/v1/contract',
			'npcink_governance_core_contract.v1',
			'core_contract_version',
			self::CORE_CONTRACT_MIN_VERSION,
			self::CORE_PLUGIN_MIN_VERSION
		);
		$toolkit = $this->dependency_contract_summary(
			'npcink-abilities-toolkit',
			'/npcink-abilities-toolkit/v1/contract',
			'npcink_abilities_toolkit_contract.v1',
			'toolkit_contract_version',
			self::TOOLKIT_CONTRACT_MIN_VERSION,
			self::TOOLKIT_PLUGIN_MIN_VERSION
		);

		$contracts = array(
			'ready'                    => ! empty( $core['compatible'] ) && ! empty( $toolkit['compatible'] ),
			'npcink-governance-core'   => $core,
			'npcink-abilities-toolkit' => $toolkit,
		);

		$this->dependency_contracts_cache = $contracts;
		set_transient( 'npcink_openclaw_adapter_dependency_contracts_v1', $contracts, self::DISCOVERY_CACHE_TTL );

		return $contracts;
	}

	/**
	 * Returns a bounded summary for one dependency contract endpoint.
	 *
	 * @param string $dependency Dependency key.
	 * @param string $route REST route.
	 * @param string $expected_schema Expected schema version.
	 * @param string $contract_version_key Contract version field.
	 * @param string $min_contract_version Minimum contract version.
	 * @param string $min_plugin_version Minimum plugin version.
	 * @return array<string,mixed>
	 */
	private function dependency_contract_summary( string $dependency, string $route, string $expected_schema, string $contract_version_key, string $min_contract_version, string $min_plugin_version ): array {
		if ( ! $this->rest_route_available( $route ) ) {
			return array(
				'available'   => false,
				'compatible'  => false,
				'route'       => $route,
				'status_code' => 404,
				'error_code'  => 'route_unavailable',
			);
		}

		$request  = new WP_REST_Request( WP_REST_Server::READABLE, $route );
		$response = rest_do_request( $request );
		$status   = method_exists( $response, 'get_status' ) ? absint( $response->get_status() ) : 500;
		$data     = method_exists( $response, 'get_data' ) ? $response->get_data() : null;
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			return array(
				'available'   => false,
				'compatible'  => false,
				'route'       => $route,
				'status_code' => $status,
				'error_code'  => $this->rest_error_code_from_data( $data ),
			);
		}

		$schema_version      = (string) ( $data['schema_version'] ?? '' );
		$contract_version    = (string) ( $data[ $contract_version_key ] ?? '' );
		$plugin_version      = (string) ( $data['plugin_version'] ?? '' );
		$schema_supported    = $expected_schema === $schema_version;
		$contract_supported  = '' !== $contract_version && version_compare( $contract_version, $min_contract_version, '>=' );
		$plugin_supported    = '' !== $plugin_version && version_compare( $plugin_version, $min_plugin_version, '>=' );
		$boundary_summary    = $this->dependency_contract_boundary_summary( $dependency, $data );
		$semantics_supported = true;
		if ( 'npcink-governance-core' === $dependency ) {
			$semantics_supported = ! empty( $boundary_summary['core_boundary_supported'] )
				&& ! empty( $boundary_summary['site_binding'] )
				&& ! empty( $boundary_summary['signed_client_fingerprint_binding'] )
				&& ! empty( $boundary_summary['implementation_posture_supported'] )
				&& ! empty( $boundary_summary['native_editor_commit_exclusion_supported'] );
		} elseif ( 'npcink-abilities-toolkit' === $dependency ) {
			$semantics_supported = ! empty( $boundary_summary['toolkit_boundary_supported'] )
				&& ! empty( $boundary_summary['schema_controls_supported'] )
				&& ! empty( $boundary_summary['write_controls_supported'] )
				&& ! empty( $boundary_summary['forbidden_payloads_omitted'] )
				&& ! empty( $boundary_summary['workflow_projection_source_supported'] );
		}

		$summary = array(
			'available'                    => true,
			'compatible'                   => $schema_supported && $contract_supported && $plugin_supported && $semantics_supported,
			'route'                        => $route,
			'status_code'                  => $status,
			'schema_version'               => $schema_version,
			'contract_version'             => $contract_version,
			'plugin_version'               => $plugin_version,
			'minimum_contract_version'     => $min_contract_version,
			'minimum_plugin_version'       => $min_plugin_version,
			'schema_supported'             => $schema_supported,
			'contract_version_supported'   => $contract_supported,
			'plugin_version_supported'     => $plugin_supported,
			'contract_semantics_supported' => $semantics_supported,
		);

		return array_merge( $summary, $boundary_summary );
	}

	/**
	 * Returns safe boundary fields from a dependency contract.
	 *
	 * @param string              $dependency Dependency key.
	 * @param array<string,mixed> $contract Dependency contract.
	 * @return array<string,mixed>
	 */
	private function dependency_contract_boundary_summary( string $dependency, array $contract ): array {
		if ( 'npcink-governance-core' === $dependency ) {
			$runtime_controls                         = is_array( $contract['runtime_controls'] ?? null ) ? $contract['runtime_controls'] : array();
			$boundary                                 = is_array( $contract['boundary'] ?? null ) ? $contract['boundary'] : array();
			$operation_classification                 = is_array( $contract['operation_classification'] ?? null ) ? $contract['operation_classification'] : array();
			$classification_values                    = $this->sanitize_string_list( is_array( $operation_classification['classification_values'] ?? null ) ? $operation_classification['classification_values'] : array() );
			$pre_classification_exclusions            = $this->sanitize_string_list( is_array( $operation_classification['pre_classification_exclusions'] ?? null ) ? $operation_classification['pre_classification_exclusions'] : array() );
			$context_bindings                         = is_array( $contract['context_bindings'] ?? null ) ? $contract['context_bindings'] : array();
			$site_binding                             = is_array( $context_bindings['site_binding'] ?? null ) ? $context_bindings['site_binding'] : array();
			$client_binding                           = is_array( $context_bindings['client_key_fingerprint'] ?? null ) ? $context_bindings['client_key_fingerprint'] : array();
			$site_fields                              = is_array( $site_binding['fields'] ?? null ) ? $site_binding['fields'] : array();
			$site_emitted_in                          = is_array( $site_binding['emitted_in'] ?? null ) ? $site_binding['emitted_in'] : array();
			$client_aliases                           = is_array( $client_binding['aliases'] ?? null ) ? $client_binding['aliases'] : array();
			$client_emitted_in                        = is_array( $client_binding['emitted_in'] ?? null ) ? $client_binding['emitted_in'] : array();
			$implementation_posture                   = is_array( $contract['implementation_posture'] ?? null ) ? $contract['implementation_posture'] : array();
			$posture_flags                            = $this->sanitize_string_list( is_array( $implementation_posture['forbidden_core_ownership_flags'] ?? null ) ? $implementation_posture['forbidden_core_ownership_flags'] : array() );
			$core_proxy_execute                       = (bool) ( $runtime_controls['core_proxy_execute'] ?? true );
			$commit_execution                         = (bool) ( $runtime_controls['commit_execution'] ?? true );
			$provider_secret_storage                  = (bool) ( $runtime_controls['provider_secret_storage'] ?? true );
			$final_write_authority                    = (string) ( $boundary['final_write_authority'] ?? '' );
			$site_binding_supported                   = in_array( 'site_url', $site_fields, true )
				&& in_array( 'home_url', $site_fields, true )
				&& in_array( 'blog_id', $site_fields, true )
				&& in_array( 'approval_context', $site_emitted_in, true )
				&& in_array( 'execution_handoff', $site_emitted_in, true )
				&& in_array( 'read_authorization_context', $site_emitted_in, true )
				&& true === (bool) ( $site_binding['fail_closed'] ?? false );
			$signed_client_fingerprint_binding        = true === (bool) ( $client_binding['emitted'] ?? false )
				&& 'signed_client_fingerprint' === (string) ( $client_binding['field'] ?? '' )
				&& in_array( 'client_key_fingerprint', $client_aliases, true )
				&& in_array( 'approval_context', $client_emitted_in, true )
				&& in_array( 'execution_handoff', $client_emitted_in, true )
				&& in_array( 'read_authorization_context', $client_emitted_in, true )
				&& 'supported_when_forwarded_by_trusted_adapter' === (string) ( $client_binding['status'] ?? '' )
				&& true === (bool) ( $client_binding['fail_closed'] ?? false );
			$implementation_posture_supported         = 'implementation_posture' === (string) ( $implementation_posture['provider_metadata_field'] ?? '' )
				&& '/wp-json/npcink-governance-core/v1/capabilities' === (string) ( $implementation_posture['capabilities_surface'] ?? '' )
				&& true === (bool) ( $implementation_posture['proposal_review_visibility'] ?? false )
				&& true === (bool) ( $implementation_posture['commit_preflight_contract_validation'] ?? false )
				&& true === (bool) ( $implementation_posture['metadata_only'] ?? false )
				&& false === (bool) ( $implementation_posture['core_records_truth'] ?? true )
				&& in_array( 'workflow_runtime', $posture_flags, true )
				&& in_array( 'queue_or_scheduler', $posture_flags, true )
				&& in_array( 'model_' . 'routing', $posture_flags, true )
				&& in_array( 'provider_' . 'credentials', $posture_flags, true )
				&& in_array( 'approval_storage', $posture_flags, true )
				&& in_array( 'audit_storage', $posture_flags, true );
			$native_editor_commit_exclusion_supported = in_array( 'native_editor_commit', $pre_classification_exclusions, true )
				&& ! in_array( 'native_editor_commit', $classification_values, true )
				&& false === (bool) ( $operation_classification['native_editor_commit_is_core_classification'] ?? true )
				&& false === (bool) ( $operation_classification['native_editor_commit_core_record_required'] ?? true );

			return array(
				'core_proxy_execute'                       => $core_proxy_execute,
				'commit_execution'                         => $commit_execution,
				'provider_secret_storage'                  => $provider_secret_storage,
				'final_write_authority'                    => $final_write_authority,
				'core_boundary_supported'                  => false === $core_proxy_execute
					&& false === $commit_execution
					&& false === $provider_secret_storage
					&& 'adapter_or_host_after_core_preflight' === $final_write_authority,
				'site_binding'                             => $site_binding_supported,
				'signed_client_fingerprint_binding'        => $signed_client_fingerprint_binding,
				'implementation_posture_supported'         => $implementation_posture_supported,
				'implementation_posture_metadata_only'     => true === (bool) ( $implementation_posture['metadata_only'] ?? false ),
				'implementation_posture_core_records_truth' => true === (bool) ( $implementation_posture['core_records_truth'] ?? true ),
				'implementation_posture_capabilities_surface' => (string) ( $implementation_posture['capabilities_surface'] ?? '' ),
				'implementation_posture_preflight_validation' => true === (bool) ( $implementation_posture['commit_preflight_contract_validation'] ?? false ),
				'implementation_posture_forbidden_flags'   => $posture_flags,
				'native_editor_commit_exclusion_supported' => $native_editor_commit_exclusion_supported,
				'pre_classification_exclusions'            => $pre_classification_exclusions,
			);
		}

		if ( 'npcink-abilities-toolkit' === $dependency ) {
			$compatibility              = is_array( $contract['compatibility'] ?? null ) ? $contract['compatibility'] : array();
			$catalog                    = is_array( $contract['catalog'] ?? null ) ? $contract['catalog'] : array();
			$schema_controls            = is_array( $contract['schema_controls'] ?? null ) ? $contract['schema_controls'] : array();
			$write_controls             = is_array( $contract['write_controls'] ?? null ) ? $contract['write_controls'] : array();
			$execution_controls         = is_array( $contract['execution_controls'] ?? null ) ? $contract['execution_controls'] : array();
			$forbidden_payloads         = is_array( $contract['forbidden_payloads'] ?? null ) ? $contract['forbidden_payloads'] : array();
			$forbidden_payload_keys     = array(
				'callback_internals',
				'permission_callable_refs',
				'approval_records',
				'audit_records',
				'app_secret_material',
				'provider_secret_material',
				'runtime_state',
				'model_routing',
				'prompt_material',
				'cloud_execution_truth',
			);
			$forbidden_payloads_omitted = true;
			foreach ( $forbidden_payload_keys as $payload_key ) {
				if ( true === (bool) ( $forbidden_payloads[ $payload_key ] ?? true ) ) {
					$forbidden_payloads_omitted = false;
					break;
				}
			}
			$toolkit_boundary_supported           = 'npcink-abilities-toolkit' === (string) ( $catalog['ability_definitions_owner'] ?? '' )
				&& 'wordpress_abilities_api' === (string) ( $catalog['ability_catalog_source'] ?? '' )
				&& '/wp-json/wp-abilities/v1/abilities' === (string) ( $catalog['ability_catalog_route'] ?? '' )
				&& 'namespace/name' === (string) ( $catalog['ability_id_format'] ?? '' )
				&& true === (bool) ( $compatibility['metadata_only'] ?? false )
				&& true === (bool) ( $compatibility['wordpress_abilities_api_required'] ?? false );
			$schema_controls_supported            = 'wordpress_abilities_api' === (string) ( $schema_controls['input_schema_source'] ?? '' )
				&& 'wordpress_abilities_api' === (string) ( $schema_controls['output_schema_source'] ?? '' )
				&& 'npcink-abilities-toolkit' === (string) ( $schema_controls['normalization_owner'] ?? '' )
				&& true === (bool) ( $schema_controls['callback_free_hashes'] ?? false )
				&& true === (bool) ( $schema_controls['stable_contract_hashes'] ?? false );
			$write_controls_supported             = true === (bool) ( $write_controls['dry_run_default'] ?? false )
				&& false === (bool) ( $write_controls['commit_default'] ?? true )
				&& true === (bool) ( $write_controls['host_governed_writes'] ?? false )
				&& 'host_runtime_after_governance' === (string) ( $write_controls['final_commit_owner'] ?? '' )
				&& 'wordpress_abilities_api' === (string) ( $execution_controls['read_execution_surface'] ?? '' )
				&& 'host_runtime_after_governance' === (string) ( $execution_controls['write_execution_surface'] ?? '' )
				&& true === (bool) ( $execution_controls['approval_context_required'] ?? false )
				&& false === (bool) ( $execution_controls['approval_storage'] ?? true )
				&& false === (bool) ( $execution_controls['audit_truth'] ?? true )
				&& false === (bool) ( $execution_controls['final_write_authorization'] ?? true );
			$workflow_projection_source_supported = true === (bool) ( $compatibility['workflow_recipe_hash_available'] ?? false )
				&& 0 === strpos( (string) ( $contract['workflow_recipes_hash'] ?? '' ), 'sha256:' );

			return array(
				'ability_count'                        => absint( $contract['ability_count'] ?? 0 ),
				'ability_ids_hash'                     => (string) ( $contract['ability_ids_hash'] ?? '' ),
				'ability_contracts_hash'               => (string) ( $contract['ability_contracts_hash'] ?? '' ),
				'workflow_recipes_hash'                => (string) ( $contract['workflow_recipes_hash'] ?? '' ),
				'ability_definitions_owner'            => (string) ( $catalog['ability_definitions_owner'] ?? '' ),
				'ability_catalog_source'               => (string) ( $catalog['ability_catalog_source'] ?? '' ),
				'ability_catalog_route'                => (string) ( $catalog['ability_catalog_route'] ?? '' ),
				'ability_id_format'                    => (string) ( $catalog['ability_id_format'] ?? '' ),
				'input_schema_source'                  => (string) ( $schema_controls['input_schema_source'] ?? '' ),
				'output_schema_source'                 => (string) ( $schema_controls['output_schema_source'] ?? '' ),
				'normalization_owner'                  => (string) ( $schema_controls['normalization_owner'] ?? '' ),
				'callback_free_hashes'                 => true === (bool) ( $schema_controls['callback_free_hashes'] ?? false ),
				'stable_contract_hashes'               => true === (bool) ( $schema_controls['stable_contract_hashes'] ?? false ),
				'dry_run_default'                      => (bool) ( $write_controls['dry_run_default'] ?? false ),
				'commit_default'                       => (bool) ( $write_controls['commit_default'] ?? true ),
				'host_governed_writes'                 => (bool) ( $write_controls['host_governed_writes'] ?? false ),
				'final_commit_owner'                   => (string) ( $write_controls['final_commit_owner'] ?? '' ),
				'read_execution_surface'               => (string) ( $execution_controls['read_execution_surface'] ?? '' ),
				'write_execution_surface'              => (string) ( $execution_controls['write_execution_surface'] ?? '' ),
				'approval_context_required'            => true === (bool) ( $execution_controls['approval_context_required'] ?? false ),
				'approval_storage'                     => true === (bool) ( $execution_controls['approval_storage'] ?? true ),
				'audit_truth'                          => true === (bool) ( $execution_controls['audit_truth'] ?? true ),
				'final_write_authorization'            => true === (bool) ( $execution_controls['final_write_authorization'] ?? true ),
				'toolkit_boundary_supported'           => $toolkit_boundary_supported,
				'schema_controls_supported'            => $schema_controls_supported,
				'write_controls_supported'             => $write_controls_supported,
				'forbidden_payloads_omitted'           => $forbidden_payloads_omitted,
				'workflow_projection_source_supported' => $workflow_projection_source_supported,
			);
		}

		return array();
	}

	/**
	 * Extracts a REST error code from response data.
	 *
	 * @param mixed $data Response data.
	 * @return string
	 */
	private function rest_error_code_from_data( $data ): string {
		if ( is_array( $data ) && is_scalar( $data['code'] ?? null ) ) {
			return sanitize_key( (string) $data['code'] );
		}

		return 'dependency_contract_unavailable';
	}

	/**
	 * Registers REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/health',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'health' ),
					'permission_callback' => array( $this, 'can_use_adapter' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/help',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'help' ),
					'permission_callback' => array( $this, 'can_use_adapter' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/capabilities',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'capabilities' ),
					'permission_callback' => array( $this, 'can_use_adapter' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/connection/manifest',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'connection_manifest' ),
					'permission_callback' => array( $this, 'can_use_adapter' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/connect/device/start',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'start_device_pairing' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/connect/device/poll',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'poll_device_pairing' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/connection/key-pairs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_client_keys' ),
					'permission_callback' => array( $this, 'can_use_adapter' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/connection/key-pairs/(?P<key_id>mk_[A-Za-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'revoke_client_key' ),
					'permission_callback' => array( $this, 'can_use_admin_session' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/run-read-ability',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run_read_ability_route' ),
					'permission_callback' => array( $this, 'can_use_adapter' ),
					'args'                => array(
						'ability_id'                 => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'input'                      => array(
							'type'    => 'object',
							'default' => array(),
						),
						'log_context'                => array(
							'type'    => 'object',
							'default' => array(),
						),
						'read_request_id'            => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'read_authorization_context' => array(
							'type'    => 'object',
							'default' => array(),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/read-requests',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_read_requests' ),
					'permission_callback' => array( $this, 'can_use_adapter' ),
					'args'                => array(
						'limit'  => array(
							'type'              => 'integer',
							'default'           => 50,
							'sanitize_callback' => 'absint',
						),
						'status' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_read_request' ),
					'permission_callback' => array( $this, 'can_use_adapter' ),
					'args'                => array(
						'ability_id'              => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'input'                   => array(
							'type'    => 'object',
							'default' => array(),
						),
						'input_hash'              => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'requested_input_summary' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'data_classes'            => array(
							'type'    => 'array',
							'default' => array(),
						),
						'purpose'                 => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'redaction_level'         => array(
							'type'              => 'string',
							'default'           => 'strict',
							'sanitize_callback' => 'sanitize_key',
						),
						'bounds'                  => array(
							'type'    => 'object',
							'default' => array(),
						),
						'caller'                  => array(
							'type'    => 'object',
							'default' => array(),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/read-requests/(?P<request_id>[A-Za-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_read_request' ),
					'permission_callback' => array( $this, 'can_use_adapter' ),
					'args'                => array(
						'request_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/proposals',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_proposals' ),
					'permission_callback' => array( $this, 'can_use_adapter' ),
					'args'                => array(
						'limit' => array(
							'type'              => 'integer',
							'default'           => 50,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_proposal' ),
					'permission_callback' => array( $this, 'can_use_adapter' ),
					'args'                => array(
						'ability_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'title'      => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'summary'    => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'input'      => array(
							'type'    => 'object',
							'default' => array(),
						),
						'preview'    => array(
							'type'    => 'object',
							'default' => array(),
						),
						'caller'     => array(
							'type'    => 'object',
							'default' => array(),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/proposals/from-plan',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_proposals_from_plan' ),
					'permission_callback' => array( $this, 'can_use_adapter' ),
					'args'                => array(
						'plan_ability_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'plan'            => array(
							'type'     => 'object',
							'required' => true,
						),
						'plan_input'      => array(
							'type'    => 'object',
							'default' => array(),
						),
						'caller'          => array(
							'type'    => 'object',
							'default' => array(),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/execute-approved-proposal',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'execute_approved_proposal_route' ),
					'permission_callback' => array( $this, 'can_use_adapter' ),
					'args'                => array(
						'proposal_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/proposals/(?P<proposal_id>[A-Za-z0-9_-]+)/execute',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'execute_approved_proposal_route' ),
					'permission_callback' => array( $this, 'can_use_adapter' ),
					'args'                => array(
						'proposal_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/proposals/(?P<proposal_id>[A-Za-z0-9_-]+)/media-optimization-readiness',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_proposal_media_optimization_readiness' ),
					'permission_callback' => array( $this, 'can_use_adapter' ),
					'args'                => array(
						'proposal_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/proposals/(?P<proposal_id>[A-Za-z0-9_-]+)/approve-and-execute',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'approve_and_execute_proposal_route' ),
					'permission_callback' => array( $this, 'can_use_unified_approve_and_execute' ),
					'args'                => array(
						'proposal_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'note'        => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

			register_rest_route(
				self::NAMESPACE,
				'/proposals/(?P<proposal_id>[A-Za-z0-9_-]+)',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_proposal' ),
						'permission_callback' => array( $this, 'can_use_adapter' ),
						'args'                => array(
							'proposal_id' => array(
								'type'              => 'string',
								'required'          => true,
								'sanitize_callback' => 'sanitize_text_field',
							),
						),
					),
				)
			);

			register_rest_route(
				self::NAMESPACE,
				'/proposals/(?P<proposal_id>[A-Za-z0-9_-]+)/commit-preflight',
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'commit_preflight' ),
						'permission_callback' => array( $this, 'can_use_adapter' ),
						'args'                => array(
							'proposal_id' => array(
								'type'              => 'string',
								'required'          => true,
								'sanitize_callback' => 'sanitize_text_field',
							),
						),
					),
				)
			);
	}

	/**
	 * Authorizes adapter use.
	 *
	 * Returns true for an authorized caller, or a WP_Error with a structured
	 * reason when authentication or authorization fails. The REST API serves
	 * the error verbatim, so signed clients can distinguish clock skew,
	 * revoked keys, replayed nonces, and scope gaps instead of debugging a
	 * generic rest_forbidden.
	 *
	 * @param WP_REST_Request|null $request Request.
	 * @return bool|WP_Error
	 */
	public function can_use_adapter( ?WP_REST_Request $request = null ) {
		$this->current_signed_client_fingerprint = '';
		$this->current_signed_authenticated      = false;

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( ! $request instanceof WP_REST_Request || ! $this->request_carries_signature_credentials( $request ) ) {
			if ( is_user_logged_in() ) {
				return new WP_Error(
					'npcink_openclaw_adapter_privilege_required',
					__( 'This WordPress account cannot use the Adapter channel. Adapter routes require an administrator session or a paired signed client.', 'npcink-ai-client-adapter' ),
					array(
						'status'        => 403,
						'reason'        => 'wordpress_account_lacks_manage_options',
						'next_step'     => __( 'Use an administrator account, or pair a signed client key through POST /connect/device/start.', 'npcink-ai-client-adapter' ),
						'pairing_route' => 'POST /' . self::NAMESPACE . '/connect/device/start',
					)
				);
			}

			return $this->adapter_authentication_required_error();
		}

		$authentication = $this->authenticate_signed_request( $request );
		if ( true === $authentication ) {
			return true;
		}

		return $authentication;
	}


	/**
	 * Authorizes manual administrator-only diagnostics.
	 *
	 * @param WP_REST_Request|null $request Request.
	 * @return bool
	 */
	public function can_use_admin_session( ?WP_REST_Request $request = null ): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Authorizes the unified approve-and-execute action.
	 *
	 * The unified action programmatically approves a pending Core proposal and
	 * then executes it, so it carries approval authority. It is reserved for a
	 * WordPress administrator session. Signed local clients must never hold
	 * that authority: a human approves the proposal in the Core admin and the
	 * same signed client then calls POST /proposals/{proposal_id}/execute.
	 * See docs/threat-model.md.
	 *
	 * @param WP_REST_Request|null $request Request.
	 * @return bool|WP_Error
	 */
	public function can_use_unified_approve_and_execute( ?WP_REST_Request $request = null ) {
		$this->current_signed_client_fingerprint = '';
		$this->current_signed_authenticated      = false;

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( $request instanceof WP_REST_Request && '' !== $this->signing_auth->signed_request_credentials( $request )['key_id'] ) {
			return new WP_Error(
				'npcink_openclaw_adapter_approve_requires_admin_session',
				__( 'The unified approve-and-execute action requires a WordPress administrator session. Signed AI clients must wait for human approval in the Npcink Governance Core admin, then call POST /proposals/{proposal_id}/execute.', 'npcink-ai-client-adapter' ),
				array(
					'status'            => 403,
					'operator_feedback' => array(
						'reason'             => 'signed_client_cannot_self_approve',
						'next_step'          => 'Approve the proposal in the Npcink Governance Core admin, then call POST /proposals/{proposal_id}/execute from the same signed client.',
						'authorized_surface' => 'wordpress_admin_session_only',
					),
				)
			);
		}

		return false;
	}

	/**
	 * Returns the non-secret local broker connection manifest.
	 *
	 * @return WP_REST_Response
	 */
	public function connection_manifest(): WP_REST_Response {
		return new WP_REST_Response( $this->connection_manifest_payload( get_current_user_id() ), 200 );
	}

	/**
	 * Starts a public-key device pairing session.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_device_pairing( WP_REST_Request $request ) {
		$started = microtime( true );
		$body    = $this->request_json_body( $request, self::MAX_DEVICE_PAIRING_BODY_BYTES );
		if ( is_wp_error( $body ) ) {
			$this->emit_operation_event( 'adapter.device_pairing.start', $started, $body );
			return $body;
		}

		$rate_limit = $this->enforce_device_pairing_start_rate_limit( $request );
		if ( is_wp_error( $rate_limit ) ) {
			$this->emit_operation_event( 'adapter.device_pairing.start', $started, $rate_limit );
			return $this->rest_response_with_retry_after( $rate_limit );
		}

		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			$error = new WP_Error(
				'npcink_openclaw_adapter_sodium_unavailable',
				__( 'Ed25519 device pairing requires the PHP sodium extension.', 'npcink-ai-client-adapter' ),
				array( 'status' => 501 )
			);
			$this->emit_operation_event( 'adapter.device_pairing.start', $started, $error );
			return $error;
		}

		$client     = is_array( $body['client'] ?? null ) ? $body['client'] : array();
		$key        = is_array( $body['key'] ?? null ) ? $body['key'] : array();
		$name       = $this->bounded_text_field( (string) ( $client['name'] ?? '' ), 120 );
		$public_key = $this->bounded_text_field( (string) ( $key['public_key'] ?? '' ), 128 );
		$scopes     = $this->connection_requested_scopes( is_array( $body['requested_scopes'] ?? null ) ? $body['requested_scopes'] : array() );

		if ( '' === $name || 'Ed25519' !== (string) ( $key['alg'] ?? '' ) || 32 !== strlen( $this->base64url_decode( $public_key ) ) ) {
			$error = new WP_Error(
				'npcink_openclaw_adapter_device_pairing_invalid',
				__( 'Device pairing requires client metadata and a base64url Ed25519 public key.', 'npcink-ai-client-adapter' ),
				array( 'status' => 400 )
			);
			$this->emit_operation_event( 'adapter.device_pairing.start', $started, $error );
			return $error;
		}

		$device_code            = 'dev_' . $this->base64url_encode( random_bytes( 32 ) );
		$user_code              = strtoupper( substr( $this->base64url_encode( random_bytes( 5 ) ), 0, 4 ) . '-' . substr( $this->base64url_encode( random_bytes( 5 ) ), 0, 4 ) );
		$expires_at             = time() + self::DEVICE_PAIRING_TTL;
		$pairings               = $this->device_pairings();
		$fingerprint            = 'sha256:' . hash(
			'sha256',
			$this->canonical_json(
				array(
					'alg'        => 'Ed25519',
					'public_key' => $public_key,
				)
			)
		);
		$pairings[ $user_code ] = array(
			'user_code'        => $user_code,
			'device_code_hash' => hash( 'sha256', $device_code ),
			'status'           => 'pending',
			'client'           => array(
				'name'           => $name,
				'device_name'    => $this->bounded_text_field( (string) ( $client['device_name'] ?? '' ), 120 ),
				'broker'         => $this->bounded_text_field( (string) ( $client['broker'] ?? '' ), 80 ),
				'broker_version' => $this->bounded_text_field( (string) ( $client['broker_version'] ?? '' ), 80 ),
			),
			'key'              => array(
				'alg'         => 'Ed25519',
				'public_key'  => $public_key,
				'fingerprint' => $fingerprint,
			),
			'scopes'           => $scopes,
			'created_at'       => gmdate( 'c' ),
			'expires_at'       => $expires_at,
		);
		update_option( self::DEVICE_PAIRING_OPTION, $this->prune_device_pairings( $pairings ), false );

		$verification_uri = admin_url( 'admin.php?page=npcink-openclaw-adapter-pair' );

		$this->emit_operation_event( 'adapter.device_pairing.start', $started, null, array( 'status_detail' => 'pending' ) );

		return new WP_REST_Response(
			array(
				'device_code'               => $device_code,
				'user_code'                 => $user_code,
				'verification_uri'          => $verification_uri,
				'verification_uri_complete' => add_query_arg( 'user_code', rawurlencode( $user_code ), $verification_uri ),
				'expires_in'                => self::DEVICE_PAIRING_TTL,
				'interval'                  => 3,
			),
			201
		);
	}

	/**
	 * Polls a device pairing session.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function poll_device_pairing( WP_REST_Request $request ) {
		$started = microtime( true );
		$body    = $this->request_json_body( $request, self::MAX_DEVICE_PAIRING_POLL_BODY_BYTES );
		if ( is_wp_error( $body ) ) {
			$this->emit_operation_event( 'adapter.device_pairing.poll', $started, $body );
			return $body;
		}

		$device_code = sanitize_text_field( (string) ( $body['device_code'] ?? '' ) );
		$rate_limit  = $this->enforce_device_pairing_poll_rate_limit( $device_code );
		if ( is_wp_error( $rate_limit ) ) {
			$this->emit_operation_event( 'adapter.device_pairing.poll', $started, $rate_limit );
			return $this->rest_response_with_retry_after( $rate_limit );
		}

		$pairing = $this->device_pairing_by_device_code( $device_code );

		if ( empty( $pairing ) || time() > (int) ( $pairing['expires_at'] ?? 0 ) ) {
			$error = new WP_Error(
				'npcink_openclaw_adapter_device_pairing_expired',
				__( 'Device pairing is expired or invalid.', 'npcink-ai-client-adapter' ),
				array( 'status' => 401 )
			);
			$this->emit_operation_event( 'adapter.device_pairing.poll', $started, $error );
			return $error;
		}

		if ( 'rejected' === (string) ( $pairing['status'] ?? '' ) ) {
			$error = new WP_Error(
				'npcink_openclaw_adapter_device_pairing_rejected',
				__( 'Device pairing was rejected.', 'npcink-ai-client-adapter' ),
				array( 'status' => 403 )
			);
			$this->emit_operation_event( 'adapter.device_pairing.poll', $started, $error, array( 'status_detail' => 'rejected' ) );
			return $error;
		}

		if ( 'approved' !== (string) ( $pairing['status'] ?? '' ) ) {
			$this->emit_operation_event( 'adapter.device_pairing.poll', $started, null, array( 'status_detail' => 'pending' ) );
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'status'  => 'pending',
					'message' => __( 'Device pairing is still pending approval.', 'npcink-ai-client-adapter' ),
				),
				202
			);
		}

		$scopes = is_array( $pairing['scopes_effective'] ?? null ) ? $pairing['scopes_effective'] : array();

		$this->emit_operation_event( 'adapter.device_pairing.poll', $started, null, array( 'status_detail' => 'approved' ) );

		return new WP_REST_Response(
			array(
				'ok'               => true,
				'connection_id'    => (string) ( $pairing['connection_id'] ?? '' ),
				'key_id'           => (string) ( $pairing['key_id'] ?? '' ),
				'site_url'         => home_url(),
				'adapter_base_url' => rest_url( self::NAMESPACE ),
				'scopes_effective' => array_values( $scopes ),
			),
			200
		);
	}

	/**
	 * Lists registered client keys for the current administrator.
	 *
	 * @return WP_REST_Response
	 */
	public function list_client_keys(): WP_REST_Response {
		$user_id = get_current_user_id();
		$records = array();
		foreach ( $this->client_key_records() as $record ) {
			if ( (int) ( $record['user_id'] ?? 0 ) === $user_id ) {
				$records[] = $this->public_client_key_record( $record );
			}
		}

		return new WP_REST_Response( array( 'key_pairs' => $records ), 200 );
	}

	/**
	 * Revokes a client key.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function revoke_client_key( WP_REST_Request $request ) {
		$key_id = sanitize_text_field( (string) $request['key_id'] );
		$result = $this->revoke_client_key_by_id( $key_id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}


	/**
	 * Authenticates a signed request and applies request-scoped identity state.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	private function authenticate_signed_request( WP_REST_Request $request ) {
		$this->current_signed_client_fingerprint = '';
		$this->current_signed_authenticated      = false;

		$verification = $this->signing_auth->verify( $request );
		if ( is_wp_error( $verification ) ) {
			return $verification;
		}

		wp_set_current_user( (int) $verification['user_id'] );
		$this->current_signed_client_fingerprint = (string) $verification['fingerprint'];
		$this->current_signed_authenticated      = true;

		return true;
	}

	/**
	 * Returns whether the request carries any Npcink signature credentials.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private function request_carries_signature_credentials( WP_REST_Request $request ): bool {
		return $this->signing_auth->carries_credentials( $request );
	}

	/**
	 * Builds the structured error for requests without usable credentials.
	 *
	 * @return WP_Error
	 */
	private function adapter_authentication_required_error(): WP_Error {
		return $this->signing_auth->authentication_required_error();
	}

	/**
	 * Encodes a value as base64url.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function base64url_encode( string $value ): string {
		return $this->signing_auth->base64url_encode( $value );
	}

	/**
	 * Decodes a base64url value.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function base64url_decode( string $value ): string {
		return $this->signing_auth->base64url_decode( $value );
	}

	/**
	 * Sanitizes and bounds one plain text field.
	 *
	 * @param string $value      Raw value.
	 * @param int    $max_length Maximum character length.
	 * @return string
	 */
	private function bounded_text_field( string $value, int $max_length ): string {
		return $this->signing_auth->bounded_text_field( $value, $max_length );
	}

	/**
	 * Normalizes requested connection scopes.
	 *
	 * @param array<mixed> $requested Requested scopes.
	 * @return array<int,string>
	 */
	private function connection_requested_scopes( array $requested ): array {
		return $this->signing_auth->requested_scopes( $requested );
	}

	/**
	 * Returns pending device pairing records.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function device_pairings(): array {
		return $this->signing_auth->pairings();
	}

	/**
	 * Prunes expired device pairing records.
	 *
	 * @param array<string,array<string,mixed>> $pairings Pairings.
	 * @return array<string,array<string,mixed>>
	 */
	private function prune_device_pairings( array $pairings ): array {
		return $this->signing_auth->prune_pairings( $pairings );
	}

	/**
	 * Returns one pairing by device code.
	 *
	 * @param string $device_code Device code.
	 * @return array<string,mixed>
	 */
	private function device_pairing_by_device_code( string $device_code ): array {
		return $this->signing_auth->pairing_by_device_code( $device_code );
	}

	/**
	 * Rate-limits device pairing starts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	private function enforce_device_pairing_start_rate_limit( WP_REST_Request $request ) {
		return $this->signing_auth->enforce_start_rate_limit( $request );
	}

	/**
	 * Rate-limits device pairing polls.
	 *
	 * @param string $device_code Device code.
	 * @return true|WP_Error
	 */
	private function enforce_device_pairing_poll_rate_limit( string $device_code ) {
		return $this->signing_auth->enforce_poll_rate_limit( $device_code );
	}

	/**
	 * Returns registered client key records.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function client_key_records(): array {
		return $this->signing_auth->key_records();
	}

	/**
	 * Returns the public projection of a client key record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return array<string,mixed>
	 */
	private function public_client_key_record( array $record ): array {
		return $this->signing_auth->public_key_record( $record );
	}

	/**
	 * Returns a device pairing record by user code for admin display.
	 *
	 * @param string $user_code User code.
	 * @return array<string,mixed>
	 */
	public function admin_device_pairing( string $user_code ): array {
		return $this->signing_auth->admin_pairing( $user_code );
	}

	/**
	 * Approves a device pairing for the current administrator.
	 *
	 * @param string $user_code   User code.
	 * @param string $admin_label Optional administrator label.
	 * @return array<string,mixed>|WP_Error
	 */
	public function approve_device_pairing( string $user_code, string $admin_label = '' ) {
		return $this->signing_auth->approve_pairing( $user_code, $admin_label );
	}

	/**
	 * Rejects a device pairing.
	 *
	 * @param string $user_code User code.
	 * @return bool
	 */
	public function reject_device_pairing( string $user_code ): bool {
		return $this->signing_auth->reject_pairing( $user_code );
	}

	/**
	 * Revokes a client key by id for a user.
	 *
	 * @param string $key_id Key id.
	 * @param int    $user_id User id.
	 * @return array<string,mixed>|WP_Error
	 */
	public function revoke_client_key_by_id( string $key_id, int $user_id ) {
		return $this->signing_auth->revoke_key_by_id( $key_id, $user_id );
	}

	/**
	 * Returns public client key records for an admin user.
	 *
	 * @param int $user_id User id.
	 * @return array<int,array<string,mixed>>
	 */
	public function admin_client_keys( int $user_id ): array {
		return $this->signing_auth->admin_client_keys( $user_id );
	}

	private function request_json_body( WP_REST_Request $request, int $max_bytes = 0 ) {
		if ( $max_bytes > 0 ) {
			$body_size = $this->validate_request_body_size( $request, $max_bytes );
			if ( is_wp_error( $body_size ) ) {
				return $body_size;
			}
		}

		$params = $request->get_json_params();
		if ( is_array( $params ) ) {
			return $params;
		}

		return new WP_Error(
			'npcink_openclaw_adapter_json_body_required',
			__( 'A JSON request body is required.', 'npcink-ai-client-adapter' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Rejects request bodies above the route's bounded payload contract.
	 *
	 * @param WP_REST_Request $request   Request.
	 * @param int             $max_bytes Maximum accepted body size.
	 * @return true|WP_Error
	 */
	private function validate_request_body_size( WP_REST_Request $request, int $max_bytes ) {
		$body = (string) $request->get_body();
		$size = strlen( $body );
		if ( $max_bytes <= 0 || $size <= $max_bytes ) {
			return true;
		}

		return new WP_Error(
			'npcink_openclaw_adapter_request_body_too_large',
			__( 'Adapter request body is too large.', 'npcink-ai-client-adapter' ),
			array(
				'status'     => 413,
				'body_bytes' => $size,
				'max_bytes'  => $max_bytes,
			)
		);
	}


	/**
	 * Builds the non-secret connection manifest and digest.
	 *
	 * @param int $user_id User id.
	 * @return array<string,mixed>
	 */
	private function connection_manifest_payload( int $user_id ): array {
		$user     = $user_id > 0 ? get_userdata( $user_id ) : wp_get_current_user();
		$username = $user && $user->exists() ? (string) $user->user_login : '';
		$base     = array(
			'schema_version'              => 'npcink_openclaw_adapter_connection.v1',
			'kind'                        => 'npcink.ai/wordpress-adapter-connection',
			'manifest_id'                 => 'npcink_manifest_' . substr( hash( 'sha256', rest_url( self::NAMESPACE ) . '|' . $username ), 0, 24 ),
			'connection_id'               => 'local-wordpress',
			'site'                        => array(
				'site_url'         => home_url(),
				'rest_url'         => rest_url(),
				'adapter_base_url' => rest_url( self::NAMESPACE ),
				'admin_origin'     => $this->url_origin( admin_url() ),
				'plugin'           => array(
					'slug'    => 'npcink-ai-client-adapter',
					'version' => NPCINK_OPENCLAW_ADAPTER_VERSION,
				),
			),
			'user'                        => array(
				'username' => $username,
			),
			'auth'                        => array(
				'preferred_method'  => 'key_pair_device_pairing',
				'supported_methods' => array(
					array(
						'type'                    => 'key_pair_device_pairing',
						'protocol'                => 'npcink-key-pair-auth.v1',
						'key_type'                => 'ed25519',
						'secret_delivery'         => 'none',
						'requires_admin_approval' => true,
					),
					array(
						'type'            => 'wp_application_password_basic',
						'fallback_only'   => true,
						'secret_slot'     => 'wordpress_application_password',
						'secret_delivery' => 'dedicated_secret_field_or_vault_only',
					),
				),
			),
			'urls'                        => array(
				'health'       => rest_url( self::NAMESPACE . '/health' ),
				'help'         => rest_url( self::NAMESPACE . '/help' ),
				'capabilities' => rest_url( self::NAMESPACE . '/capabilities' ),
				'device_start' => rest_url( self::NAMESPACE . '/connect/device/start' ),
				'device_poll'  => rest_url( self::NAMESPACE . '/connect/device/poll' ),
				'key_pairs'    => rest_url( self::NAMESPACE . '/connection/key-pairs' ),
			),
			'capabilities'                => array(
				'read'  => array(
					'requires_adapter_auth' => true,
				),
				'write' => array(
					'mode'                            => 'proposal_only',
					'direct_wordpress_write_allowed'  => false,
					'requires_npcink_governance_core' => true,
				),
			),
			'client_policy'               => $this->client_policy( false ),
			'contract'                    => $this->adapter_contract_metadata(),
			'execution_profile_readiness' => $this->execution_profile_readiness(),
			'dependency_contracts'        => $this->dependency_contracts(),
		);

		$base['integrity'] = array(
			'canonicalization' => 'recursive_ksort_json',
			'manifest_sha256'  => 'sha256:' . hash( 'sha256', $this->canonical_json( $base ) ),
			'digest_excludes'  => array( 'client_policy.boundary_enforcement' ),
		);

		$base['client_policy']['boundary_enforcement'] = $this->boundary_enforcement_policy();

		return $base;
	}


	/**
	 * Returns the currently authenticated signed local client fingerprint.
	 *
	 * @return string
	 */
	private function current_signed_client_fingerprint(): string {
		return $this->signing_auth->sanitize_signed_client_fingerprint( $this->current_signed_client_fingerprint );
	}


	/**
	 * Returns adapter health.
	 *
	 * @return WP_REST_Response
	 */
	public function health(): WP_REST_Response {
		$dependencies         = $this->dependency_status();
		$dependency_contracts = $this->dependency_contracts();

		return new WP_REST_Response(
			array(
				'adapter'                             => 'npcink-ai-client-adapter',
				'version'                             => NPCINK_OPENCLAW_ADAPTER_VERSION,
				'distribution_mode'                   => 'adapter_entry_with_separate_governance_and_ability_plugins',
				'core_capabilities'                   => (bool) ( $dependencies['items']['npcink-governance-core']['available'] ?? false ),
				'abilities_catalog'                   => (bool) ( $dependencies['items']['wordpress-abilities-api']['available'] ?? false ),
				'abilities_toolkit'                   => (bool) ( $dependencies['items']['npcink-abilities-toolkit']['available'] ?? false ),
				'dependencies_ready'                  => empty( $dependencies['missing'] ),
				'dependencies'                        => $dependencies['items'],
				'dependency_contracts_ready'          => (bool) ( $dependency_contracts['ready'] ?? false ),
				'dependency_contracts'                => $dependency_contracts,
				'dependency_count'                    => count( $dependencies['items'] ),
				'missing_dependencies'                => $dependencies['missing'],
				'core_proxy_execute'                  => false,
				'commit_execution'                    => false,
				'approval_surface'                    => 'npcink_governance_core_admin',
				'core_app_token_configured'           => 'none' !== $this->core_app_token_source(),
				'core_app_token_source'               => $this->core_app_token_source(),
				'contract'                            => $this->adapter_contract_metadata(),
				'execution_handoff_posture'           => $this->execution_handoff_posture(),
				'client_policy'                       => $this->client_policy(),
				'ai_request_log_context_fields'       => array(
					'proposal_id',
					'correlation_id',
					'external_thread_id',
					'openclaw_thread_id',
					'ability_id',
					'adapter_request_id',
					'adapter_route',
					'governance_source',
					'npcink_governance_core.proposal_id',
					'npcink_governance_core.correlation_id',
				),
				'core_app_token_required_scopes'      => array(
					'capabilities:read',
					'proposals:read',
					'proposals:create',
					'commit:preflight',
					'read_requests:create',
					'read_requests:read',
					'read_requests:preflight',
				),
				'sensitive_read_authorization'        => array(
					'core_truth'                     => true,
					'required_field'                 => 'read_authorization_required',
					'required_policy'                => 'core_read_authorization_required',
					'error_code'                     => 'npcink_openclaw_adapter_core_read_authorization_required',
					'request_route'                  => 'POST /read-requests',
					'status_route'                   => 'GET /read-requests/{request_id}',
					'execution_route'                => 'POST /run-read-ability with read_request_id',
					'unsupported_without_core_grant' => 'fail_closed',
				),
				'approved_proposal_execution_routes'  => array(
					'POST /execute-approved-proposal',
					'POST /proposals/{proposal_id}/execute',
					'POST /proposals/{proposal_id}/approve-and-execute',
				),
				'admin_session_only_execution_routes' => array(
					'POST /proposals/{proposal_id}/approve-and-execute',
				),
				'signed_client_execution_routes'      => array(
					'POST /execute-approved-proposal',
					'POST /proposals/{proposal_id}/execute',
				),
				'supported_execute_ability_ids'       => self::supported_execute_ability_ids(),
				'execution_profile_readiness'         => $this->execution_profile_readiness(),
				'execution_input_contract'            => array(
					'single'                      => 'proposal.input, with ability-specific required fields',
					'batch'                       => 'proposal.input.write_actions[].target_ability_id + proposal.input.write_actions[].input',
					'max_actions'                 => self::MAX_EXECUTION_ACTIONS,
					'partial_success'             => false,
					'execute_commit_policy'       => 'Adapter execute routes are final write paths and normalize ability input to dry_run=false and commit=true.',
					'dry_run_verification_policy' => 'Dry-run proposal verification stops at Adapter commit-preflight; do not call execute for a dry-run-only check.',
				),
				'plan_proposal_routes'                => array(
					'POST /proposals/from-plan',
				),
				'supported_plan_ability_ids'          => Supported_Plan_Abilities::ids(),
				'proposal_status_routes'              => array(
					'GET /proposals',
					'GET /proposals/{proposal_id}',
					'GET /proposals/{proposal_id}/media-optimization-readiness',
				),
				'supported_guidance'                  => array(
					'read'                        => array(
						'governance_mode'              => 'direct_read',
						'execution_surface'            => 'wp_abilities_rest',
						'read_policy_values'           => array(
							'direct_read_public',
							'direct_read_internal',
							'direct_read_sensitive',
							'core_read_authorization_required',
						),
						'sensitivity_values'           => array( 'public', 'internal', 'sensitive' ),
						'redaction_required_field'     => 'redaction_required',
						'read_audit_mode'              => 'adapter_read_envelope',
						'sensitive_read_authorization' => array(
							'core_truth'      => true,
							'required_field'  => 'read_authorization_required',
							'required_policy' => 'core_read_authorization_required',
							'request_route'   => 'POST /read-requests',
							'status_route'    => 'GET /read-requests/{request_id}',
							'execution_route' => 'POST /run-read-ability with read_request_id',
							'unsupported_without_core_grant' => 'fail_closed',
						),
					),
					'proposal_status'             => array(
						'governance_mode'        => 'core_proposal_read_proxy',
						'execution_surface'      => 'npcink_governance_core_rest',
						'core_required_scope'    => 'proposals:read',
						'approval_surface'       => 'npcink_governance_core_admin',
						'proposal_status_routes' => array(
							'GET /proposals',
							'GET /proposals/{proposal_id}',
							'GET /proposals/{proposal_id}/media-optimization-readiness',
						),
					),
					'write'                       => array(
						'governance_mode'   => 'proposal_required',
						'execution_surface' => 'adapter_after_core_preflight',
					),
					'approved_proposal_execution' => array(
						'governance_mode'          => 'core_approved_commit_preflight_required',
						'execution_surface'        => 'wp_abilities_rest_after_core_preflight',
						'core_required_scope'      => 'commit:preflight',
						'core_commit_execution'    => false,
						'supported_ability_ids'    => self::supported_execute_ability_ids(),
						'execution_input_contract' => array(
							'single'                     => 'proposal.input',
							'batch'                      => 'proposal.input.write_actions[]',
							'max_actions'                => self::MAX_EXECUTION_ACTIONS,
							'partial_success'            => false,
							'execute_commit_policy'      => 'final_write_normalizes_dry_run_false_commit_true',
							'dry_run_verification_route' => 'POST /proposals/{proposal_id}/commit-preflight',
						),
					),
					'unified_approve_and_execute' => array(
						'governance_mode'          => 'core_approval_then_adapter_execution',
						'execution_surface'        => 'wp_abilities_rest_after_core_preflight',
						'approval_surface'         => 'npcink_governance_core_admin',
						'authorization'            => 'wordpress_admin_session_only',
						'signed_client_access'     => 'forbidden_use_execute_after_human_approval',
						'core_commit_execution'    => false,
						'supported_ability_ids'    => self::supported_execute_ability_ids(),
						'execution_input_contract' => array(
							'single'                     => 'proposal.input',
							'batch'                      => 'proposal.input.write_actions[]',
							'max_actions'                => self::MAX_EXECUTION_ACTIONS,
							'partial_success'            => false,
							'execute_commit_policy'      => 'final_write_normalizes_dry_run_false_commit_true',
							'dry_run_verification_route' => 'POST /proposals/{proposal_id}/commit-preflight',
						),
					),
					'plan_to_proposal'            => array(
						'governance_mode'       => 'direct_read_plan_to_core_proposals',
						'execution_surface'     => 'npcink_governance_core_rest',
						'core_required_scope'   => 'proposals:create',
						'core_route'            => 'POST /npcink-governance-core/v1/proposals/from-plan',
						'plan_fields_preserved' => array(
							'batch_id',
							'issue_types',
							'post_ids',
							'attachment_ids',
							'write_actions',
							'preview',
							'risk',
							'requires_approval',
							'commit_execution',
							'dry_run',
							'manual_review',
							'skipped_destructive_candidates',
							'issue_counts',
							'action_count',
						),
					),
				),
				'permission_capability'               => 'manage_options',
				'current_user_authorized'             => current_user_can( 'manage_options' ),
				'adapter_base_url'                    => rest_url( self::NAMESPACE ),
				'health_url'                          => rest_url( self::NAMESPACE . '/health' ),
				'help_url'                            => rest_url( self::NAMESPACE . '/help' ),
				'capabilities_url'                    => rest_url( self::NAMESPACE . '/capabilities' ),
				'proposal_list_url'                   => rest_url( self::NAMESPACE . '/proposals' ),
				'proposal_detail_url'                 => rest_url( self::NAMESPACE . '/proposals/{proposal_id}' ),
				'auth'                                => array(
					'type'        => 'wordpress_rest_application_password',
					'header'      => 'Authorization: Basic base64(username:application_password)',
					'recommended' => 'dedicated_administrator_application_password_for_initial_openclaw_poc',
				),
			),
			200
		);
	}

	/**
	 * Returns adapter route help.
	 *
	 * @return WP_REST_Response
	 */
	public function help(): WP_REST_Response {
		$route_groups = $this->help_route_groups();

		return new WP_REST_Response(
			array(
				'adapter'                             => 'npcink-ai-client-adapter',
				'namespace'                           => self::NAMESPACE,
				'base_url'                            => rest_url( self::NAMESPACE ),
				'auth'                                => array(
					'type'       => 'wordpress_rest_application_password',
					'capability' => 'manage_options',
					'header'     => 'Authorization: Basic base64(username:application_password)',
				),
				'routes'                              => $this->help_routes_flat( $route_groups ),
				'route_groups'                        => $route_groups,
				'core_required_scopes'                => array(
					'proposal_status'    => 'proposals:read',
					'proposal_create'    => 'proposals:create',
					'proposal_from_plan' => 'proposals:create',
					'commit_preflight'   => 'commit:preflight',
				),
				'approval_surface'                    => 'npcink_governance_core_admin',
				'core_app_token_configured'           => 'none' !== $this->core_app_token_source(),
				'core_app_token_source'               => $this->core_app_token_source(),
				'contract'                            => $this->adapter_contract_metadata(),
				'execution_handoff_posture'           => $this->execution_handoff_posture(),
				'dependency_contracts'                => $this->dependency_contracts(),
				'client_policy'                       => $this->client_policy(),
				'distribution_mode'                   => 'adapter_entry_with_separate_governance_and_ability_plugins',
				'dependencies'                        => $this->dependency_status()['items'],
				'ai_request_log_context'              => array(
					'accepted_param'  => 'log_context',
					'query_fields'    => array(
						'proposal_id',
						'correlation_id',
						'external_thread_id',
						'openclaw_thread_id',
					),
					'target'          => 'wpai_request_log_context',
					'required_fields' => array(
						'proposal_id',
						'correlation_id',
						'ability_id',
						'adapter_request_id',
						'adapter_route',
						'governance_source',
					),
				),
				'core_app_token_required_scopes'      => array(
					'capabilities:read',
					'proposals:read',
					'proposals:create',
					'commit:preflight',
					'read_requests:create',
					'read_requests:read',
					'read_requests:preflight',
				),
				'approved_proposal_execution_routes'  => array(
					'POST /execute-approved-proposal',
					'POST /proposals/{proposal_id}/execute',
					'POST /proposals/{proposal_id}/approve-and-execute',
				),
				'admin_session_only_execution_routes' => array(
					'POST /proposals/{proposal_id}/approve-and-execute',
				),
				'signed_client_execution_routes'      => array(
					'POST /execute-approved-proposal',
					'POST /proposals/{proposal_id}/execute',
				),
				'supported_execute_ability_ids'       => self::supported_execute_ability_ids(),
				'execution_profile_readiness'         => $this->execution_profile_readiness(),
				'execution_input_contract'            => array(
					'single'                      => 'proposal.input, with ability-specific required fields',
					'batch'                       => 'proposal.input.write_actions[].target_ability_id + proposal.input.write_actions[].input',
					'max_actions'                 => self::MAX_EXECUTION_ACTIONS,
					'partial_success'             => false,
					'execute_commit_policy'       => 'Adapter execute routes are final write paths and normalize ability input to dry_run=false and commit=true.',
					'dry_run_verification_policy' => 'Dry-run proposal verification stops at Adapter commit-preflight; do not call execute for a dry-run-only check.',
				),
				'plan_proposal_routes'                => array(
					'POST /proposals/from-plan',
				),
				'supported_plan_ability_ids'          => Supported_Plan_Abilities::ids(),
				'proposal_status_routes'              => array(
					'GET /proposals',
					'GET /proposals/{proposal_id}',
					'GET /proposals/{proposal_id}/media-optimization-readiness',
				),
				'non_goals'                           => array(
					'workflow_runtime'       => false,
					'mcp_runtime'            => false,
					'final_commit_execution' => false,
				),
			),
			200
		);
	}

	/**
	 * Returns human-readable route groups for help output.
	 *
	 * @return array<string,array<int,string>>
	 */
	private function help_route_groups(): array {
		return array(
			'connection'                   => array(
				'GET /health',
				'GET /help',
				'GET /capabilities',
				'GET /connection/manifest',
				'POST /connect/device/start',
				'POST /connect/device/poll',
				'GET /connection/key-pairs',
				'DELETE /connection/key-pairs/{key_id}',
			),
			'generic_read'                 => array(
				'POST /run-read-ability',
			),
			'sensitive_read_authorization' => array(
				'POST /read-requests',
				'GET /read-requests',
				'GET /read-requests/{request_id}',
			),
			'proposal_status'              => array(
				'GET /proposals',
				'GET /proposals/{proposal_id}',
				'GET /proposals/{proposal_id}/media-optimization-readiness',
			),
			'governance'                   => array(
				'POST /proposals',
				'POST /proposals/from-plan',
				'POST /proposals/{proposal_id}/commit-preflight',
				'POST /execute-approved-proposal',
				'POST /proposals/{proposal_id}/execute',
				'POST /proposals/{proposal_id}/approve-and-execute',
			),
		);
	}

	/**
	 * Returns machine-readable route help rows.
	 *
	 * @param array<string,array<int,string>> $route_groups Route groups.
	 * @return array<int,array<string,string>>
	 */
	private function help_routes_flat( array $route_groups ): array {
		$routes = array();

		foreach ( $route_groups as $group => $labels ) {
			foreach ( $labels as $label ) {
				$routes[] = $this->help_route_row( (string) $label, (string) $group );
			}
		}

		return $routes;
	}

	/**
	 * Builds one machine-readable route help row from a label.
	 *
	 * @param string $label Route label, such as GET /health.
	 * @param string $group Route group.
	 * @return array<string,string>
	 */
	private function help_route_row( string $label, string $group ): array {
		$parts  = preg_split( '/\s+/', trim( $label ), 2 );
		$method = isset( $parts[0] ) ? strtoupper( (string) $parts[0] ) : '';
		$path   = isset( $parts[1] ) ? (string) $parts[1] : '';

		return array(
			'method'  => $method,
			'path'    => $path,
			'purpose' => $this->help_route_purpose( $method, $path, $group ),
			'group'   => $group,
		);
	}

	/**
	 * Returns a concise route purpose for agent route discovery.
	 *
	 * @param string $method Route method.
	 * @param string $path Route path.
	 * @param string $group Route group.
	 * @return string
	 */
	private function help_route_purpose( string $method, string $path, string $group ): string {
		$key      = $method . ' ' . $path;
		$purposes = array(
			'GET /health'                           => 'Check adapter health and connection state.',
			'GET /help'                             => 'Discover adapter routes and handoff guidance.',
			'GET /capabilities'                     => 'List Core capabilities and governance guidance.',
			'GET /connection/manifest'              => 'Return the non-secret local broker connection manifest.',
			'POST /connect/device/start'            => 'Start a public-key device pairing session.',
			'POST /connect/device/poll'             => 'Poll a public-key device pairing session.',
			'GET /connection/key-pairs'             => 'List registered key-pair clients for the current user.',
			'DELETE /connection/key-pairs/{key_id}' => 'Revoke a registered key-pair client.',
			'POST /run-read-ability'                => 'Run a direct-read ability by ability_id.',
			'GET /read-requests'                    => 'List Core sensitive read request statuses through Adapter.',
			'POST /read-requests'                   => 'Create a Core sensitive read authorization request through Adapter.',
			'GET /read-requests/{request_id}'       => 'Read one Core sensitive read request status through Adapter.',
			'GET /proposals'                        => 'List Core proposal statuses for polling.',
			'GET /proposals/{proposal_id}'          => 'Read one Core proposal status by proposal_id.',
			'GET /proposals/{proposal_id}/media-optimization-readiness' => 'Read Adapter-owned execution readiness checks for one media optimization proposal.',
			'POST /proposals'                       => 'Create a Core proposal for governed work.',
			'POST /proposals/from-plan'             => 'Forward a read-only plan output to Core plan-to-proposal intake.',
			'POST /proposals/{proposal_id}/commit-preflight' => 'Advanced diagnostic route: run Core commit preflight without final writes and cache the one-time handoff for the next Adapter execute call; dry-run verification stops here.',
			'POST /execute-approved-proposal'       => 'Final write route: execute one approved proposal after Core commit preflight or a cached Adapter preflight handoff; normalizes ability input to dry_run=false and commit=true.',
			'POST /proposals/{proposal_id}/execute' => 'Final write route: execute one approved proposal by id after Core commit preflight or a cached Adapter preflight handoff; normalizes ability input to dry_run=false and commit=true.',
			'POST /proposals/{proposal_id}/approve-and-execute' => 'Final write route for WordPress administrator sessions only: approve a pending proposal through Core, then preflight and execute one supported single input or write_actions payload with dry_run=false and commit=true. Signed AI clients must not call this route; wait for human approval in the Core admin and use POST /proposals/{proposal_id}/execute.',
		);

		if ( isset( $purposes[ $key ] ) ) {
			return $purposes[ $key ];
		}

		return 'Call adapter route ' . trim( $key ) . '.';
	}

	/**
	 * Returns Core capabilities.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function capabilities() {
		$response = $this->dispatch_upstream( 'GET', '/npcink-governance-core/v1/capabilities' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( is_array( $data ) ) {
			$this->upstream_dispatch->prime_capabilities( $data );
		}

		return $response;
	}

	/**
	 * Runs a direct-read ability from request input.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_read_ability_route( WP_REST_Request $request ) {
		$body_size = $this->validate_request_body_size( $request, self::MAX_REST_BODY_BYTES );
		if ( is_wp_error( $body_size ) ) {
			return $body_size;
		}

		return $this->run_read_ability(
			(string) $request->get_param( 'ability_id' ),
			$this->request_input( $request ),
			$this->request_log_context( $request, (string) $request->get_param( 'ability_id' ) ),
			$this->read_authorization_params( $request )
		);
	}

	/**
	 * Creates a Core sensitive read request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_read_request( WP_REST_Request $request ) {
		$body_size = $this->validate_request_body_size( $request, self::MAX_REST_BODY_BYTES );
		if ( is_wp_error( $body_size ) ) {
			return $body_size;
		}

		$ability_id = sanitize_text_field( (string) $request->get_param( 'ability_id' ) );
		$payload    = array(
			'ability_id'              => $ability_id,
			'input'                   => $this->request_input( $request ),
			'input_hash'              => sanitize_text_field( (string) $request->get_param( 'input_hash' ) ),
			'requested_input_summary' => sanitize_textarea_field( (string) $request->get_param( 'requested_input_summary' ) ),
			'data_classes'            => $this->sanitize_string_list( is_array( $request->get_param( 'data_classes' ) ) ? (array) $request->get_param( 'data_classes' ) : array() ),
			'purpose'                 => sanitize_textarea_field( (string) $request->get_param( 'purpose' ) ),
			'redaction_level'         => sanitize_key( (string) $request->get_param( 'redaction_level' ) ),
			'bounds'                  => $this->object_param( $request, 'bounds' ),
			'caller'                  => $this->proposal_caller_context( $request, $ability_id ),
		);

		return $this->dispatch_upstream( 'POST', '/npcink-governance-core/v1/read-requests', $payload, false, true );
	}

	/**
	 * Lists Core sensitive read requests.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_read_requests( WP_REST_Request $request ) {
		return $this->dispatch_upstream(
			'GET',
			'/npcink-governance-core/v1/read-requests',
			array(
				'limit'  => min( self::MAX_PROPOSAL_LIST_LIMIT, max( 1, absint( $request->get_param( 'limit' ) ) ) ),
				'status' => sanitize_key( (string) $request->get_param( 'status' ) ),
			),
			true
		);
	}

	/**
	 * Gets one Core sensitive read request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_read_request( WP_REST_Request $request ) {
		$request_id = sanitize_text_field( (string) $request->get_param( 'request_id' ) );
		return $this->dispatch_upstream( 'GET', '/npcink-governance-core/v1/read-requests/' . rawurlencode( $request_id ) );
	}

	/**
	 * Lists Core proposals through the adapter read-only status proxy.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_proposals( WP_REST_Request $request ) {
		$limit = min( self::MAX_PROPOSAL_LIST_LIMIT, max( 1, absint( $request->get_param( 'limit' ) ) ) );

		return $this->dispatch_upstream(
			'GET',
			'/npcink-governance-core/v1/proposals',
			array(
				'limit' => $limit,
			),
			true
		);
	}

	/**
	 * Gets one Core proposal through the adapter read-only status proxy.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_proposal( WP_REST_Request $request ) {
		$proposal_id = (string) $request->get_param( 'proposal_id' );

		$response = $this->dispatch_upstream( 'GET', '/npcink-governance-core/v1/proposals/' . rawurlencode( $proposal_id ) );
		if ( $response instanceof WP_REST_Response ) {
			$data = $response->get_data();
			if ( is_array( $data ) ) {
				$response->set_data( $this->augment_proposal_status_response( $proposal_id, $data ) );
			}
		}

		return $response;
	}

	/**
	 * Gets Adapter-owned media optimization readiness for one Core proposal.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_proposal_media_optimization_readiness( WP_REST_Request $request ) {
		$proposal_id = (string) $request->get_param( 'proposal_id' );
		$response    = $this->dispatch_upstream( 'GET', '/npcink-governance-core/v1/proposals/' . rawurlencode( $proposal_id ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $response instanceof WP_REST_Response ? $response->get_data() : array();
		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'npcink_openclaw_adapter_invalid_core_proposal',
				__( 'Core proposal detail response is invalid.', 'npcink-ai-client-adapter' ),
				array( 'status' => 502 )
			);
		}

		$readiness = $this->media_optimization_readiness( $data );
		$status    = $this->proposal_derived_execution_status( $proposal_id, $data, $readiness );

		return new WP_REST_Response(
			array(
				'proposal_id'                  => sanitize_text_field( '' !== $proposal_id ? $proposal_id : (string) ( $data['proposal_id'] ?? '' ) ),
				'media_optimization'           => is_array( $readiness ),
				'media_optimization_readiness' => is_array( $readiness ) ? $readiness : array(
					'ready'              => true,
					'status'             => 'not_applicable',
					'first_failed_check' => '',
					'checks'             => array(),
					'artifact'           => null,
				),
				'adapter_status'               => $status,
				'execution_status'             => $status['execution_status'],
				'effective_status'             => $status['effective_status'],
				'executable'                   => $status['executable'],
				'non_executable_reason'        => $status['non_executable_reason'],
				'preflight_status'             => $status['preflight_status'],
				'commit_execution'             => false,
			),
			200
		);
	}

	/**
	 * Adds Adapter-owned execution/readiness status to a Core proposal payload.
	 *
	 * Core remains the proposal, approval, and preflight audit truth. Adapter
	 * owns only the derived execution view because final writes happen outside
	 * Core through the local Abilities API.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param array<string,mixed> $proposal Core proposal payload.
	 * @return array<string,mixed>
	 */
	private function augment_proposal_status_response( string $proposal_id, array $proposal ): array {
		$proposal_id = sanitize_text_field( '' !== $proposal_id ? $proposal_id : (string) ( $proposal['proposal_id'] ?? '' ) );
		$readiness   = $this->media_optimization_readiness( $proposal );
		$status      = $this->proposal_derived_execution_status( $proposal_id, $proposal, $readiness );

		$proposal['adapter_status']        = $status;
		$proposal['execution_status']      = $status['execution_status'];
		$proposal['effective_status']      = $status['effective_status'];
		$proposal['executable']            = $status['executable'];
		$proposal['non_executable_reason'] = $status['non_executable_reason'];
		$proposal['preflight_status']      = $status['preflight_status'];
		$review_summary                    = $this->proposal_review_summary( $proposal );
		if ( ! empty( $review_summary ) ) {
			$proposal['review_summary']       = implode( "\n", $review_summary );
			$proposal['review_summary_lines'] = $review_summary;
		}

		if ( is_array( $readiness ) ) {
			$proposal['media_optimization_readiness'] = $readiness;
		}

		return $proposal;
	}

	/**
	 * Builds the derived executable state shown by Adapter proposal detail.
	 *
	 * @param string                   $proposal_id Proposal id.
	 * @param array<string,mixed>      $proposal Core proposal payload.
	 * @param array<string,mixed>|null $readiness Media optimization readiness.
	 * @return array<string,mixed>
	 */
	private function proposal_derived_execution_status( string $proposal_id, array $proposal, ?array $readiness ): array {
		$core_status      = sanitize_key( (string) ( $proposal['status'] ?? '' ) );
		$execution_record = $this->execution_record_for_proposal( $proposal_id );
		$public_record    = is_array( $execution_record ) ? $this->public_execution_record( $execution_record ) : null;
		$cached_handoff   = $this->cached_preflight_handoff_for_status( $proposal_id, $proposal );
		$audit_preflight  = $this->latest_preflight_audit_event( $proposal );

		$execution_status = 'not_started';
		if ( is_array( $execution_record ) ) {
			$record_status    = sanitize_key( (string) ( $execution_record['status'] ?? '' ) );
			$execution_status = 'succeeded' === $record_status ? 'succeeded' : ( 'failed' === $record_status ? 'failed' : $record_status );
		}

		$preflight_status = 'not_issued';
		if ( is_array( $cached_handoff ) ) {
			$preflight_status = 'issued_adapter_cached';
		} elseif ( is_array( $audit_preflight ) ) {
			$preflight_status = 'issued_core_audit_only';
		}

		$executable            = true;
		$non_executable_reason = '';
		$effective_status      = '' !== $core_status ? $core_status : 'unknown';
		if ( 'succeeded' === $execution_status ) {
			$executable            = false;
			$non_executable_reason = 'already_executed';
			$effective_status      = 'executed';
		} elseif ( 'failed' === $execution_status ) {
			$executable            = false;
			$non_executable_reason = 'execution_failed';
			$effective_status      = 'execution_failed';
		} elseif ( 'approved' !== $core_status ) {
			$executable            = false;
			$non_executable_reason = '' !== $core_status ? 'proposal_' . $core_status : 'proposal_status_unknown';
		} elseif ( ! is_array( $cached_handoff ) && is_array( $audit_preflight ) ) {
			$executable            = false;
			$non_executable_reason = 'preflight_already_issued';
		} elseif ( is_array( $readiness ) && false === (bool) ( $readiness['ready'] ?? true ) ) {
			$executable            = false;
			$non_executable_reason = sanitize_key( (string) ( $readiness['first_failed_check'] ?? 'media_optimization_not_ready' ) );
		} elseif ( false === $this->proposal_preview_marks_executable( $proposal ) ) {
			$executable            = false;
			$non_executable_reason = 'proposal_preview_not_ready';
		}

		return array(
			'core_status'           => $core_status,
			'execution_status'      => $execution_status,
			'effective_status'      => $effective_status,
			'executable'            => $executable,
			'non_executable_reason' => $non_executable_reason,
			'preflight_status'      => $preflight_status,
			'preflight_issued'      => 'not_issued' !== $preflight_status,
			'commit_execution'      => false,
			'execution_record'      => $public_record,
			'cached_preflight'      => is_array( $cached_handoff ) ? array(
				'status'         => sanitize_key( (string) ( $cached_handoff['status'] ?? '' ) ),
				'correlation_id' => sanitize_text_field( (string) ( $cached_handoff['correlation_id'] ?? '' ) ),
				'issued_at'      => sanitize_text_field( (string) ( $cached_handoff['issued_at'] ?? '' ) ),
			) : null,
			'preflight_audit'       => is_array( $audit_preflight ) ? $audit_preflight : null,
		);
	}

	/**
	 * Checks generic Core preview readiness flags that Adapter can understand.
	 *
	 * @param array<string,mixed> $proposal Core proposal payload.
	 * @return bool
	 */
	private function proposal_preview_marks_executable( array $proposal ): bool {
		$preview = is_array( $proposal['preview'] ?? null ) ? $proposal['preview'] : array();
		if ( array_key_exists( 'proposal_ready', $preview ) && false === (bool) $preview['proposal_ready'] ) {
			return false;
		}
		if ( ! empty( $preview['needs_input'] ?? array() ) || ! empty( $preview['preflight_blockers'] ?? array() ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Builds media optimization readiness checks without downloading artifacts.
	 *
	 * @param array<string,mixed> $proposal Core proposal payload.
	 * @return array<string,mixed>|null
	 */
	private function media_optimization_readiness( array $proposal ): ?array {
		if ( ! $this->proposal_is_media_optimization( $proposal ) ) {
			return null;
		}

		$artifact                = $this->media_optimization_derivative_artifact( $proposal );
		$repairs                 = $this->normalize_media_optimization_reference_repairs( $this->media_optimization_reference_repairs( $proposal ) );
		$valid_actions           = $this->validate_plan_write_action_inputs( is_array( $proposal['input'] ?? null ) ? $proposal['input'] : array() );
		$artifact_check          = $this->media_optimization_artifact_expiry_check( $artifact );
		$artifact_contract_valid = $this->media_derivative_artifact_contract_is_valid( $artifact );
		$artifact_id             = is_string( $artifact['artifact_id'] ?? null ) ? $artifact['artifact_id'] : '';
		$checks                  = array(
			'cloud_artifact_receive_available' => array(
				'ready'  => function_exists( 'npcink_cloud_addon_receive_media_derivative_artifact' ),
				'status' => function_exists( 'npcink_cloud_addon_receive_media_derivative_artifact' ) ? 'available' : 'missing',
			),
			'cloud_addon_configured'           => array(
				'ready'  => ! function_exists( 'npcink_cloud_addon_is_configured' ) || (bool) npcink_cloud_addon_is_configured(),
				'status' => function_exists( 'npcink_cloud_addon_is_configured' ) ? ( (bool) npcink_cloud_addon_is_configured() ? 'configured' : 'not_configured' ) : 'unknown',
			),
			'artifact_present'                 => array(
				'ready'       => 1 === preg_match( '/^art_[0-9a-f]{32}$/D', $artifact_id ),
				'status'      => 1 === preg_match( '/^art_[0-9a-f]{32}$/D', $artifact_id ) ? 'present' : 'missing',
				'artifact_id' => $artifact_id,
			),
			'artifact_contract_valid'          => array(
				'ready'  => $artifact_contract_valid,
				'status' => $artifact_contract_valid ? 'valid' : 'invalid',
			),
			'artifact_not_expired'             => $artifact_check,
			'adapter_validator_aligned'        => array(
				'ready'  => ! is_wp_error( $valid_actions ),
				'status' => is_wp_error( $valid_actions ) ? 'invalid' : 'valid',
				'code'   => is_wp_error( $valid_actions ) ? $valid_actions->get_error_code() : '',
			),
			'content_reference_scan_completed' => array(
				'ready'                    => is_array( $repairs ) && array_key_exists( 'scanned_count', $repairs ),
				'status'                   => is_array( $repairs ) && array_key_exists( 'scanned_count', $repairs ) ? 'completed' : 'missing',
				'scanned_count'            => absint( $repairs['scanned_count'] ?? 0 ),
				'post_count'               => absint( $repairs['post_count'] ?? 0 ),
				'replacement_rule_count'   => absint( $repairs['replacement_rule_count'] ?? 0 ),
				'actual_replacement_count' => absint( $repairs['actual_replacement_count'] ?? 0 ),
				'unmatched_rules'          => is_array( $repairs['unmatched_rules'] ?? null ) ? $repairs['unmatched_rules'] : array(),
			),
		);

		$ready              = true;
		$first_failed_check = '';
		foreach ( $checks as $check_name => $check ) {
			if ( false === (bool) ( $check['ready'] ?? false ) ) {
				$ready = false;
				if ( '' === $first_failed_check ) {
					$first_failed_check = sanitize_key( $check_name );
				}
			}
		}

		return array(
			'ready'              => $ready,
			'status'             => $ready ? 'ready' : 'blocked',
			'first_failed_check' => $first_failed_check,
			'checks'             => $checks,
			'artifact'           => ! $artifact_contract_valid ? null : array(
				'artifact_id' => $artifact_id,
				'mime_type'   => sanitize_text_field( (string) ( $artifact['mime_type'] ?? '' ) ),
				'expires_at'  => sanitize_text_field( (string) ( $artifact['expires_at'] ?? '' ) ),
			),
		);
	}

	/**
	 * Returns whether the proposal is the bounded media optimization batch.
	 *
	 * @param array<string,mixed> $proposal Core proposal payload.
	 * @return bool
	 */
	private function proposal_is_media_optimization( array $proposal ): bool {
		$preview = is_array( $proposal['preview'] ?? null ) ? $proposal['preview'] : array();
		$source  = is_array( $preview['source'] ?? null ) ? $preview['source'] : array();
		if ( 'npcink-abilities-toolkit/build-media-optimization-plan' === (string) ( $source['plan_ability_id'] ?? '' ) ) {
			return true;
		}
		if ( is_array( $preview['media_optimization'] ?? null ) ) {
			return true;
		}

		$input = is_array( $proposal['input'] ?? null ) ? $proposal['input'] : array();
		foreach ( (array) ( $input['write_actions'] ?? array() ) as $action ) {
			if ( is_array( $action ) && 'npcink-abilities-toolkit/adopt-cloud-media-derivative' === (string) ( $action['target_ability_id'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the derivative artifact descriptor from proposal input.
	 *
	 * @param array<string,mixed> $proposal Core proposal payload.
	 * @return array<string,mixed>
	 */
	private function media_optimization_derivative_artifact( array $proposal ): array {
		$input = is_array( $proposal['input'] ?? null ) ? $proposal['input'] : array();
		foreach ( (array) ( $input['write_actions'] ?? array() ) as $action ) {
			if ( ! is_array( $action ) || 'npcink-abilities-toolkit/adopt-cloud-media-derivative' !== (string) ( $action['target_ability_id'] ?? '' ) ) {
				continue;
			}
			$action_input = is_array( $action['input'] ?? null ) ? $action['input'] : array();
			return is_array( $action_input['derivative_artifact'] ?? null ) ? $action_input['derivative_artifact'] : array();
		}

		return is_array( $input['derivative_artifact'] ?? null ) ? $input['derivative_artifact'] : array();
	}

	/**
	 * Returns content reference repair evidence from preview or input.
	 *
	 * @param array<string,mixed> $proposal Core proposal payload.
	 * @return array<string,mixed>|null
	 */
	private function media_optimization_reference_repairs( array $proposal ): ?array {
		$preview = is_array( $proposal['preview'] ?? null ) ? $proposal['preview'] : array();
		$media   = is_array( $preview['media_optimization'] ?? null ) ? $preview['media_optimization'] : array();
		if ( is_array( $media['derivative_preview']['content_reference_repairs'] ?? null ) ) {
			return $media['derivative_preview']['content_reference_repairs'];
		}
		if ( is_array( $media['content_reference_repairs'] ?? null ) ) {
			return $media['content_reference_repairs'];
		}

		$input = is_array( $proposal['input'] ?? null ) ? $proposal['input'] : array();
		foreach ( (array) ( $input['write_actions'] ?? array() ) as $action ) {
			if ( ! is_array( $action ) || 'npcink-abilities-toolkit/adopt-cloud-media-derivative' !== (string) ( $action['target_ability_id'] ?? '' ) ) {
				continue;
			}
			$action_input = is_array( $action['input'] ?? null ) ? $action['input'] : array();
			if ( is_array( $action_input['content_reference_repairs'] ?? null ) ) {
				return $action_input['content_reference_repairs'];
			}
			if ( isset( $action_input['expected_content_reference_replacement_count'] ) ) {
				return array(
					'scanned_count'     => 0,
					'post_count'        => absint( $action_input['expected_content_reference_post_count'] ?? 0 ),
					'replacement_count' => absint( $action_input['expected_content_reference_replacement_count'] ?? 0 ),
				);
			}
		}

		return null;
	}

	/**
	 * Normalizes old and new content reference repair count shapes.
	 *
	 * @param array<string,mixed>|null $repairs Repair evidence.
	 * @return array<string,mixed>|null
	 */
	private function normalize_media_optimization_reference_repairs( ?array $repairs ): ?array {
		if ( ! is_array( $repairs ) ) {
			return null;
		}

		$replacement_rule_count   = isset( $repairs['replacement_rule_count'] ) ? absint( $repairs['replacement_rule_count'] ) : null;
		$actual_replacement_count = isset( $repairs['actual_replacement_count'] ) ? absint( $repairs['actual_replacement_count'] ) : null;
		$unmatched_rules          = is_array( $repairs['unmatched_rules'] ?? null ) ? $repairs['unmatched_rules'] : array();

		if ( null !== $replacement_rule_count && null !== $actual_replacement_count ) {
			$repairs['replacement_rule_count']   = $replacement_rule_count;
			$repairs['actual_replacement_count'] = $actual_replacement_count;
			$repairs['unmatched_rules']          = $unmatched_rules;
			return $repairs;
		}

		$repairs_rows = is_array( $repairs['repairs'] ?? null ) ? $repairs['repairs'] : array();
		if ( ! empty( $repairs_rows ) ) {
			$derived_rule_count   = 0;
			$derived_actual_count = 0;
			$derived_unmatched    = array();

			foreach ( $repairs_rows as $repair ) {
				if ( ! is_array( $repair ) ) {
					continue;
				}
				$operations    = is_array( $repair['operations'] ?? null ) ? array_values( $repair['operations'] ) : array();
				$patch_preview = is_array( $repair['patch_preview'] ?? null ) ? array_values( $repair['patch_preview'] ) : array();
				$post_id       = absint( $repair['post_id'] ?? 0 );

				if ( ! empty( $operations ) ) {
					$derived_rule_count += count( $operations );
				} else {
					$derived_rule_count += absint( $repair['operation_count'] ?? ( $repair['replacement_count'] ?? 0 ) );
				}

				if ( ! empty( $patch_preview ) ) {
					foreach ( $patch_preview as $index => $row ) {
						if ( ! is_array( $row ) ) {
							continue;
						}
						$applied               = absint( $row['applied'] ?? 0 );
						$derived_actual_count += $applied;
						if ( 0 === $applied ) {
							$operation           = is_array( $operations[ $index ] ?? null ) ? $operations[ $index ] : array();
							$derived_unmatched[] = array(
								'post_id'         => $post_id,
								'operation_index' => absint( $index ),
								'find'            => sanitize_text_field( (string) ( $operation['find'] ?? ( $row['find'] ?? '' ) ) ),
							);
						}
					}
				} else {
					$derived_actual_count += absint( $repair['actual_replacement_count'] ?? ( $repair['replacement_count'] ?? 0 ) );
				}
			}

			$repairs['replacement_rule_count']   = $derived_rule_count;
			$repairs['actual_replacement_count'] = $derived_actual_count;
			$repairs['unmatched_rules']          = $derived_unmatched;
			return $repairs;
		}

		$fallback_count                      = absint( $repairs['replacement_count'] ?? 0 );
		$repairs['replacement_rule_count']   = null === $replacement_rule_count ? $fallback_count : $replacement_rule_count;
		$repairs['actual_replacement_count'] = null === $actual_replacement_count ? $fallback_count : $actual_replacement_count;
		$repairs['unmatched_rules']          = $unmatched_rules;

		return $repairs;
	}

	/**
	 * Builds a non-mutating human review summary for proposal detail.
	 *
	 * @param array<string,mixed> $proposal Core proposal payload.
	 * @return array<int,string>
	 */
	private function proposal_review_summary( array $proposal ): array {
		if ( ! $this->proposal_is_media_optimization( $proposal ) ) {
			return array();
		}

		$input          = is_array( $proposal['input'] ?? null ) ? $proposal['input'] : array();
		$preview        = is_array( $proposal['preview'] ?? null ) ? $proposal['preview'] : array();
		$media          = is_array( $preview['media_optimization'] ?? null ) ? $preview['media_optimization'] : array();
		$derivative     = is_array( $media['derivative_preview'] ?? null ) ? $media['derivative_preview'] : array();
		$before         = is_array( $derivative['before'] ?? null ) ? $derivative['before'] : array();
		$after          = is_array( $derivative['after'] ?? null ) ? $derivative['after'] : array();
		$artifact       = $this->media_optimization_derivative_artifact( $proposal );
		$repairs        = $this->normalize_media_optimization_reference_repairs( $this->media_optimization_reference_repairs( $proposal ) );
		$adopt_input    = array();
		$metadata_input = array();

		foreach ( (array) ( $input['write_actions'] ?? array() ) as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$action_input = is_array( $action['input'] ?? null ) ? $action['input'] : array();
			if ( 'npcink-abilities-toolkit/adopt-cloud-media-derivative' === (string) ( $action['target_ability_id'] ?? '' ) ) {
				$adopt_input = $action_input;
			}
			if ( 'npcink-abilities-toolkit/update-media-details' === (string) ( $action['target_ability_id'] ?? '' ) ) {
				$metadata_input = $action_input;
			}
		}

		$attachment_id = absint( $adopt_input['attachment_id'] ?? ( $metadata_input['attachment_id'] ?? ( $before['attachment_id'] ?? 0 ) ) );
		$lines         = array();
		if ( $attachment_id > 0 ) {
			$mime_type = sanitize_text_field( (string) ( $artifact['mime_type'] ?? ( $after['mime_type'] ?? '' ) ) );
			$format    = false !== strpos( $mime_type, '/' ) ? strtoupper( substr( $mime_type, strrpos( $mime_type, '/' ) + 1 ) ) : strtoupper( $mime_type );
			$lines[]   = sprintf(
				/* translators: 1: attachment id, 2: media format. */
				__( 'Replace attachment %1$d with the reviewed %2$s derivative.', 'npcink-ai-client-adapter' ),
				$attachment_id,
				'' !== $format ? $format : __( 'optimized', 'npcink-ai-client-adapter' )
			);
		}

		$before_size = $this->media_review_dimensions( $before );
		$after_size  = $this->media_review_dimensions( $after );
		if ( '' !== $before_size || '' !== $after_size ) {
			$lines[] = sprintf(
				/* translators: 1: before size, 2: after size. */
				__( 'Dimensions: %1$s -> %2$s.', 'npcink-ai-client-adapter' ),
				'' !== $before_size ? $before_size : __( 'unknown', 'npcink-ai-client-adapter' ),
				'' !== $after_size ? $after_size : __( 'unknown', 'npcink-ai-client-adapter' )
			);
		}

		$file_name = sanitize_file_name( (string) ( $adopt_input['file_name'] ?? ( $after['file_name'] ?? basename( (string) ( $after['relative_file'] ?? '' ) ) ) ) );
		if ( '' !== $file_name ) {
			$lines[] = sprintf(
				/* translators: %s: file name. */
				__( 'Local file name: %s.', 'npcink-ai-client-adapter' ),
				$file_name
			);
		}

		if ( is_array( $repairs ) ) {
			$post_ids   = array_values( array_filter( array_map( 'absint', (array) ( $adopt_input['expected_content_reference_post_ids'] ?? array() ) ) ) );
			$post_count = absint( $repairs['post_count'] ?? ( $adopt_input['expected_content_reference_post_count'] ?? count( $post_ids ) ) );
			$lines[]    = sprintf(
				/* translators: 1: post count, 2: actual replacement count, 3: rule count. */
				__( 'Repair post-content media references in %1$d post(s): %2$d actual replacement(s) from %3$d reviewed rule(s).', 'npcink-ai-client-adapter' ),
				$post_count,
				absint( $repairs['actual_replacement_count'] ?? 0 ),
				absint( $repairs['replacement_rule_count'] ?? 0 )
			);
		}

		if ( ! empty( $metadata_input ) ) {
			$lines[] = __( 'Update reviewed media title, alt text, caption, description, or attribution metadata in the same approval.', 'npcink-ai-client-adapter' );
		}
		$lines[] = __( 'Keep a local backup so the media file and post references can be rolled back after approval.', 'npcink-ai-client-adapter' );

		return array_values( array_unique( array_filter( $lines ) ) );
	}

	/**
	 * Returns width x height text when known.
	 *
	 * @param array<string,mixed> $media Media state.
	 * @return string
	 */
	private function media_review_dimensions( array $media ): string {
		$width  = absint( $media['width'] ?? ( $media['metadata']['width'] ?? 0 ) );
		$height = absint( $media['height'] ?? ( $media['metadata']['height'] ?? 0 ) );
		if ( $width <= 0 || $height <= 0 ) {
			return '';
		}

		return $width . 'x' . $height;
	}

	/**
	 * Returns a readiness check for artifact expiration.
	 *
	 * @param array<string,mixed> $artifact Artifact descriptor.
	 * @return array<string,mixed>
	 */
	private function media_optimization_artifact_expiry_check( array $artifact ): array {
		$expires_at = sanitize_text_field( (string) ( $artifact['expires_at'] ?? '' ) );
		if ( '' === $expires_at ) {
			return array(
				'ready'      => false,
				'status'     => 'missing_expires_at',
				'expires_at' => '',
			);
		}

		$expires = $this->media_derivative_expiry_timestamp( $expires_at );
		if ( $expires <= 0 ) {
			return array(
				'ready'      => false,
				'status'     => 'invalid_expires_at',
				'expires_at' => $expires_at,
			);
		}

		return array(
			'ready'      => $expires > time(),
			'status'     => $expires > time() ? 'valid' : 'expired',
			'expires_at' => $expires_at,
		);
	}

	/**
	 * Validates the exact local 11-field media derivative artifact contract.
	 *
	 * @param array<string,mixed> $artifact Artifact descriptor.
	 * @return bool
	 */
	private function media_derivative_artifact_contract_is_valid( array $artifact ): bool {
		$expected_keys = array(
			'artifact_id',
			'expires_at',
			'mime_type',
			'format',
			'width',
			'height',
			'filesize_bytes',
			'sha256',
			'suggested_filename',
			'filename_basis',
			'processing_warnings',
		);
		$actual_keys   = array_keys( $artifact );
		sort( $actual_keys );
		sort( $expected_keys );
		if ( $actual_keys !== $expected_keys ) {
			return false;
		}

		if ( ! is_string( $artifact['artifact_id'] ) || 1 !== preg_match( '/^art_[0-9a-f]{32}$/D', $artifact['artifact_id'] ) ) {
			return false;
		}
		if ( ! is_string( $artifact['expires_at'] ) || $this->media_derivative_expiry_timestamp( $artifact['expires_at'] ) <= time() ) {
			return false;
		}

		$format_by_mime = array(
			'image/webp' => 'webp',
			'image/avif' => 'avif',
			'image/jpeg' => 'jpeg',
			'image/png'  => 'png',
		);
		$mime_type      = is_string( $artifact['mime_type'] ) ? $artifact['mime_type'] : '';
		$format         = is_string( $artifact['format'] ) ? $artifact['format'] : '';
		if ( ! isset( $format_by_mime[ $mime_type ] ) || $format_by_mime[ $mime_type ] !== $format ) {
			return false;
		}

		if (
			! is_int( $artifact['width'] )
			|| ! is_int( $artifact['height'] )
			|| $artifact['width'] < 1
			|| $artifact['height'] < 1
			|| $artifact['width'] > 8192
			|| $artifact['height'] > 8192
			|| $artifact['width'] * $artifact['height'] > 16777216
			|| ! is_int( $artifact['filesize_bytes'] )
			|| $artifact['filesize_bytes'] < 1
			|| $artifact['filesize_bytes'] > 26214400
			|| ! is_string( $artifact['sha256'] )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $artifact['sha256'] )
		) {
			return false;
		}

		$suggested_filename = is_string( $artifact['suggested_filename'] ) ? $artifact['suggested_filename'] : '';
		if ( '' === $suggested_filename || strlen( $suggested_filename ) > 120 || sanitize_file_name( $suggested_filename ) !== $suggested_filename ) {
			return false;
		}

		$filename_basis      = is_array( $artifact['filename_basis'] ) ? $artifact['filename_basis'] : array();
		$filename_basis_keys = array_keys( $filename_basis );
		sort( $filename_basis_keys );
		if (
			array( 'final_sanitize_unique_required', 'owner', 'strategy' ) !== $filename_basis_keys
			|| 'wordpress_write_ability_final' !== ( $filename_basis['owner'] ?? null )
			|| 'format_checksum' !== ( $filename_basis['strategy'] ?? null )
			|| true !== ( $filename_basis['final_sanitize_unique_required'] ?? null )
		) {
			return false;
		}

		$warnings = $artifact['processing_warnings'];
		if ( ! is_array( $warnings ) || count( $warnings ) > 20 ) {
			return false;
		}
		foreach ( $warnings as $warning ) {
			if ( ! is_string( $warning ) || strlen( $warning ) > 200 || sanitize_text_field( $warning ) !== $warning ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Parses canonical UTC RFC3339 artifact expiry without date normalization.
	 *
	 * @param string $value Expiry.
	 * @return int
	 */
	private function media_derivative_expiry_timestamp( string $value ): int {
		$utc     = new \DateTimeZone( 'UTC' );
		$formats = array(
			'!Y-m-d\TH:i:s\Z'   => 'Y-m-d\TH:i:s\Z',
			'!Y-m-d\TH:i:sP'    => 'Y-m-d\TH:i:sP',
			'!Y-m-d\TH:i:s.u\Z' => 'Y-m-d\TH:i:s.u\Z',
			'!Y-m-d\TH:i:s.uP'  => 'Y-m-d\TH:i:s.uP',
		);
		foreach ( $formats as $parse_format => $roundtrip_format ) {
			$parsed     = \DateTimeImmutable::createFromFormat( $parse_format, $value, $utc );
			$errors     = \DateTimeImmutable::getLastErrors();
			$has_errors = is_array( $errors ) && ( (int) ( $errors['warning_count'] ?? 0 ) > 0 || (int) ( $errors['error_count'] ?? 0 ) > 0 );
			if (
				$parsed instanceof \DateTimeImmutable
				&& ! $has_errors
				&& 0 === $parsed->getOffset()
				&& $value === $parsed->format( $roundtrip_format )
			) {
				return $parsed->getTimestamp();
			}
		}

		return 0;
	}

	/**
	 * Returns the latest Core preflight audit event, when visible in detail.
	 *
	 * @param array<string,mixed> $proposal Core proposal payload.
	 * @return array<string,mixed>|null
	 */
	private function latest_preflight_audit_event( array $proposal ): ?array {
		$latest = null;
		foreach ( (array) ( $proposal['audit_timeline'] ?? array() ) as $event ) {
			if ( ! is_array( $event ) || 'commit.preflighted' !== (string) ( $event['event_name'] ?? '' ) ) {
				continue;
			}
			$metadata = is_array( $event['metadata'] ?? null ) ? $event['metadata'] : array();
			$latest   = array(
				'event_name'     => 'commit.preflighted',
				'correlation_id' => sanitize_text_field( (string) ( $metadata['correlation_id'] ?? ( $event['correlation_id'] ?? '' ) ) ),
				'created_at'     => sanitize_text_field( (string) ( $event['created_at'] ?? '' ) ),
			);
		}

		return $latest;
	}

	/**
	 * Returns a cached Adapter preflight handoff without consuming it.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param array<string,mixed> $proposal Core proposal payload.
	 * @return array<string,mixed>|null
	 */
	private function cached_preflight_handoff_for_status( string $proposal_id, array $proposal ): ?array {
		$records = $this->preflight_handoffs();
		$record  = is_array( $records[ $this->execution_record_key( $proposal_id ) ] ?? null ) ? $records[ $this->execution_record_key( $proposal_id ) ] : array();
		if ( empty( $record ) || 'issued' !== (string) ( $record['status'] ?? '' ) ) {
			return null;
		}

		$approved_hash = sanitize_text_field( (string) ( $record['approved_input_hash'] ?? '' ) );
		if ( '' === $approved_hash || $approved_hash !== $this->proposal_input_hash( $proposal ) ) {
			return null;
		}

		return $record;
	}

	/**
	 * Creates a Core proposal.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_proposal( WP_REST_Request $request ) {
		$body_size = $this->validate_request_body_size( $request, self::MAX_REST_BODY_BYTES );
		if ( is_wp_error( $body_size ) ) {
			return $body_size;
		}

		$started       = microtime( true );
		$ability_id    = (string) $request->get_param( 'ability_id' );
		$event_context = $this->observability_request_context( $request, array( 'ability_id' => $ability_id ) );
		$input         = $this->object_param( $request, 'input' );
		$valid_input   = $this->validate_proposal_create_input( $ability_id, $input );
		if ( is_wp_error( $valid_input ) ) {
			$this->emit_operation_event( 'adapter.proposal.create', $started, $valid_input, $event_context );
			return $valid_input;
		}

		$params = array(
			'ability_id' => $ability_id,
			'title'      => (string) $request->get_param( 'title' ),
			'summary'    => (string) $request->get_param( 'summary' ),
			'input'      => $input,
			'preview'    => $this->object_param( $request, 'preview' ),
			'caller'     => $this->proposal_caller_context( $request, $ability_id ),
		);

		$response = $this->dispatch_upstream( 'POST', '/npcink-governance-core/v1/proposals', $params );
		$this->emit_operation_event( 'adapter.proposal.create', $started, is_wp_error( $response ) ? $response : null, $event_context );

		return $response;
	}

	/**
	 * Validates Adapter-owned proposal input before forwarding to Core.
	 *
	 * Only abilities with local execution profiles are validated here. Other
	 * proposal-required abilities remain Core-owned at proposal creation time.
	 *
	 * @param string              $ability_id Ability id.
	 * @param array<string,mixed> $input Proposal input.
	 * @return true|WP_Error
	 */
	private function validate_proposal_create_input( string $ability_id, array $input, bool $allow_output_refs = false, ?int $action_index = null ) {
		$ability_id = sanitize_text_field( $ability_id );
		$profiles   = self::execution_profiles();
		if ( ! isset( $profiles[ $ability_id ] ) ) {
			return true;
		}

		return $this->execution_input_validator->validate_execute_action_input( 'proposal_create', $ability_id, $input, absint( $input['post_id'] ?? 0 ), $action_index, $allow_output_refs );
	}

	/**
	 * Creates Core proposals from a read-only plan output.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_proposals_from_plan( WP_REST_Request $request ) {
		$body_size = $this->validate_request_body_size( $request, self::MAX_REST_BODY_BYTES );
		if ( is_wp_error( $body_size ) ) {
			return $body_size;
		}

		$started         = microtime( true );
		$plan_ability_id = sanitize_text_field( (string) $request->get_param( 'plan_ability_id' ) );
		$event_context   = $this->observability_request_context( $request, array( 'ability_id' => $plan_ability_id ) );
		if ( ! Supported_Plan_Abilities::contains( $plan_ability_id ) ) {
			$error = new WP_Error(
				'npcink_openclaw_adapter_plan_ability_unsupported',
				__( 'This planning ability is not implemented by the adapter plan-to-proposal bridge.', 'npcink-ai-client-adapter' ),
				array(
					'status'                     => 400,
					'supported_plan_ability_ids' => Supported_Plan_Abilities::ids(),
				)
			);
			$error = $this->error_with_operator_feedback( $error, $this->plan_handoff_operator_feedback( $error, $plan_ability_id ) );
			$this->emit_operation_event( 'adapter.proposal.plan_ingest', $started, $error, $event_context );
			return $error;
		}

		$plan             = $this->normalize_plan_batch_metadata( $this->object_param( $request, 'plan' ) );
		$valid_plan_input = $this->validate_plan_write_action_inputs( $plan );
		if ( is_wp_error( $valid_plan_input ) ) {
			$valid_plan_input = $this->error_with_operator_feedback( $valid_plan_input, $this->plan_handoff_operator_feedback( $valid_plan_input, $plan_ability_id ) );
			$this->emit_operation_event( 'adapter.proposal.plan_ingest', $started, $valid_plan_input, $event_context );
			return $valid_plan_input;
		}

		$params = array(
			'plan_ability_id' => $plan_ability_id,
			'plan'            => $plan,
			'plan_input'      => $this->object_param( $request, 'plan_input' ),
			'caller'          => $this->proposal_caller_context( $request, $plan_ability_id ),
		);

		$response = $this->dispatch_upstream( 'POST', '/npcink-governance-core/v1/proposals/from-plan', $params );
		if ( is_wp_error( $response ) ) {
			$response = $this->error_with_operator_feedback( $response, $this->plan_handoff_operator_feedback( $response, $plan_ability_id ) );
		} elseif ( $response instanceof WP_REST_Response ) {
			$data = $response->get_data();
			if ( is_array( $data ) ) {
				$batch_review_feedback = $this->batch_review_feedback_from_proposals( $data );
				if ( ! empty( $batch_review_feedback ) ) {
					$data['batch_review_feedback'] = $batch_review_feedback;
					$response->set_data( $data );
				}
			}
		}
		$this->emit_operation_event( 'adapter.proposal.plan_ingest', $started, is_wp_error( $response ) ? $response : null, $event_context );

		return $response;
	}

	/**
	 * Makes dependent/output-reference plan batches explicit for Core versions that do not infer it.
	 *
	 * @param array<string,mixed> $plan_payload Plan output or success envelope.
	 * @return array<string,mixed>
	 */
	private function normalize_plan_batch_metadata( array $plan_payload ): array {
		$is_envelope = is_array( $plan_payload['data'] ?? null );
		$plan        = $is_envelope ? (array) $plan_payload['data'] : $plan_payload;
		$actions     = is_array( $plan['write_actions'] ?? null ) ? array_values( $plan['write_actions'] ) : array();
		if ( count( $actions ) > 1 ) {
			$plan['atomicity']                = 'non_atomic';
			$plan['partial_success_possible'] = true;
		}

		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}

			$depends_on = is_array( $action['depends_on'] ?? null ) ? array_filter( $action['depends_on'] ) : array();
			if ( ! empty( $depends_on ) || ! empty( $this->execution_input_validator->collect_output_references( $action['input'] ?? array() ) ) ) {
				$plan['proposal_mode']            = 'batch';
				$plan['batch_approval']           = true;
				$plan['atomicity']                = 'non_atomic';
				$plan['partial_success_possible'] = true;
				break;
			}
		}

		if ( $is_envelope ) {
			$plan_payload['data'] = $plan;
			return $plan_payload;
		}

		return $plan;
	}

	/**
	 * Validates profiled write action input before Core creates plan proposals.
	 *
	 * Core remains the proposal creation and blocked-item truth. Adapter only
	 * rejects inputs it already owns through the execution profile registry, so
	 * single proposal and plan-to-proposal intake fail on the same schema rules.
	 *
	 * @param array<string,mixed> $plan_payload Plan output or success envelope.
	 * @return true|WP_Error
	 */
	private function validate_plan_write_action_inputs( array $plan_payload ) {
		$plan = is_array( $plan_payload['data'] ?? null ) ? $plan_payload['data'] : $plan_payload;
		if ( ! is_array( $plan ) ) {
			return true;
		}

		$write_actions     = is_array( $plan['write_actions'] ?? null ) ? array_values( $plan['write_actions'] ) : array();
		$blocked_items     = array();
		$available_outputs = array();
		foreach ( $write_actions as $index => $raw_action ) {
			if ( ! is_array( $raw_action ) ) {
				$blocked_items[] = array(
					'index'      => $index,
					'block_code' => 'npcink_openclaw_adapter_plan_action_input_invalid',
					'reason'     => __( 'Each write_actions item must be an object.', 'npcink-ai-client-adapter' ),
				);
				continue;
			}

			$target_ability_id = sanitize_text_field( (string) ( $raw_action['target_ability_id'] ?? '' ) );
			if ( '' === $target_ability_id ) {
				$blocked_items[] = array(
					'index'      => $index,
					'block_code' => 'npcink_openclaw_adapter_plan_action_input_invalid',
					'reason'     => __( 'Each write_actions item must declare target_ability_id.', 'npcink-ai-client-adapter' ),
				);
				continue;
			}

			$action_id = sanitize_key( (string) ( $raw_action['action_id'] ?? '' ) );
			if ( '' === $action_id ) {
				$action_id = 'action-' . ( $index + 1 );
			}
			if ( isset( $available_outputs[ $action_id ] ) ) {
				$blocked_items[] = array(
					'index'             => $index,
					'action_id'         => $action_id,
					'target_ability_id' => $target_ability_id,
					'block_code'        => 'npcink_openclaw_adapter_write_action_duplicate_id',
					'reason'            => __( 'Each write_actions item must have a unique action_id.', 'npcink-ai-client-adapter' ),
				);
				continue;
			}

			$proposal_ready = array_key_exists( 'proposal_ready', $raw_action ) ? (bool) $raw_action['proposal_ready'] : true;
			$requires_input = array_values( array_map( 'sanitize_key', (array) ( $raw_action['requires_input'] ?? array() ) ) );
			if ( ! $proposal_ready && ! empty( $requires_input ) ) {
				$blocked_items[] = array(
					'index'             => $index,
					'action_id'         => $action_id,
					'target_ability_id' => $target_ability_id,
					'block_code'        => 'npcink_openclaw_adapter_plan_action_input_invalid',
					'reason'            => __( 'This action requires additional input before proposal creation.', 'npcink-ai-client-adapter' ),
					'requires_input'    => $requires_input,
				);
				continue;
			}

			$input       = is_array( $raw_action['input'] ?? null ) ? $raw_action['input'] : array();
			$valid_refs  = $this->execution_input_validator->validate_output_references( 'proposal_create', $input, $available_outputs, $index );
			$valid_input = is_wp_error( $valid_refs ) ? $valid_refs : $this->validate_proposal_create_input( $target_ability_id, $input, true, $index );
			if ( is_wp_error( $valid_input ) ) {
				$error_data = $valid_input->get_error_data();
				$error_data = is_array( $error_data ) ? $error_data : array();
				$blocked    = array(
					'index'             => $index,
					'action_id'         => $action_id,
					'target_ability_id' => $target_ability_id,
					'block_code'        => $valid_input->get_error_code(),
					'reason'            => $valid_input->get_error_message(),
				);

				foreach ( array( 'field', 'supported_input_fields', 'allowed_values', 'reference' ) as $key ) {
					if ( array_key_exists( $key, $error_data ) ) {
						$blocked[ $key ] = $error_data[ $key ];
					}
				}

				$blocked_items[] = $blocked;
				continue;
			}

			$available_outputs[ $action_id ] = true;
		}

		if ( empty( $blocked_items ) ) {
			return true;
		}

		return new WP_Error(
			'npcink_openclaw_adapter_plan_action_input_invalid',
			__( 'Plan write action input failed Adapter proposal validation.', 'npcink-ai-client-adapter' ),
			array(
				'status'         => 400,
				'proposal_count' => 0,
				'blocked_count'  => count( $blocked_items ),
				'blocked_items'  => $blocked_items,
			)
		);
	}

	/**
	 * Runs Core commit preflight.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function commit_preflight( WP_REST_Request $request ) {
		$started     = microtime( true );
		$proposal_id = (string) $request->get_param( 'proposal_id' );
		$response    = $this->dispatch_upstream( 'POST', '/npcink-governance-core/v1/proposals/' . rawurlencode( $proposal_id ) . '/commit-preflight' );
		if ( ! is_wp_error( $response ) && $response instanceof WP_REST_Response ) {
			$data = $response->get_data();
			if ( is_array( $data ) ) {
				$proposal = is_array( $data['proposal'] ?? null ) ? $data['proposal'] : array();
				if ( empty( $proposal ) ) {
					$proposal_detail = $this->get_core_proposal_data( $proposal_id );
					if ( ! is_wp_error( $proposal_detail ) ) {
						$proposal = $proposal_detail;
					}
				}

				$handoff                                  = $this->store_preflight_handoff( $proposal_id, $proposal, $data );
				$data['adapter_preflight_handoff_cached'] = is_array( $handoff );
				$data['adapter_execution_route']          = '/wp-json/' . self::NAMESPACE . '/proposals/' . rawurlencode( $proposal_id ) . '/execute';
				$data['execution_handoff_posture']        = $this->execution_handoff_posture();
				$batch_review_feedback                    = $this->batch_review_feedback_from_preflight( $data, $proposal );
				if ( ! empty( $batch_review_feedback ) ) {
					$data['batch_review_feedback'] = $batch_review_feedback;
				}
				$response->set_data( $data );
			}
		}
		$this->emit_operation_event(
			'adapter.commit.preflight',
			$started,
			is_wp_error( $response ) ? $response : null,
			$this->observability_request_context( $request, array( 'proposal_id' => $proposal_id ) )
		);

		return $response;
	}

	/**
	 * Executes one approved Core proposal after commit preflight.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function execute_approved_proposal_route( WP_REST_Request $request ) {
		$started   = microtime( true );
		$body_size = $this->validate_request_body_size( $request, self::MAX_LIGHT_POST_BODY_BYTES );
		if ( is_wp_error( $body_size ) ) {
			$this->emit_operation_event( 'adapter.proposal.execute', $started, $body_size );
			return $body_size;
		}

		$proposal_id   = sanitize_text_field( (string) $request->get_param( 'proposal_id' ) );
		$event_context = $this->observability_request_context( $request, array( 'proposal_id' => $proposal_id ) );
		if ( '' === $proposal_id ) {
			$error = new WP_Error(
				'npcink_openclaw_adapter_proposal_id_required',
				__( 'proposal_id is required.', 'npcink-ai-client-adapter' ),
				array( 'status' => 400 )
			);
			$this->emit_operation_event( 'adapter.proposal.execute', $started, $error, $event_context );
			return $error;
		}

		$proposal = $this->get_core_proposal_data( $proposal_id );
		if ( is_wp_error( $proposal ) ) {
			$this->emit_operation_event( 'adapter.proposal.execute', $started, $proposal, $event_context );
			return $proposal;
		}

		$execution = $this->execute_core_approved_proposal( $request, $proposal_id, $proposal );
		if ( is_wp_error( $execution ) ) {
			$this->emit_operation_event( 'adapter.proposal.execute', $started, $execution, $event_context );
			return $execution;
		}

		$this->emit_operation_event(
			'adapter.proposal.execute',
			$started,
			null,
			array_merge(
				$event_context,
				array(
					'proposal_id'        => $proposal_id,
					'ability_id'         => (string) ( $execution['ability_id'] ?? '' ),
					'correlation_id'     => (string) ( $execution['correlation_id'] ?? '' ),
					'adapter_request_id' => (string) ( $execution['adapter_request_id'] ?? '' ),
					'executed_count'     => (int) ( $execution['executed_count'] ?? 0 ),
					'failed_count'       => (int) ( $execution['failed_count'] ?? 0 ),
				),
			)
		);

		return new WP_REST_Response(
			$this->public_execution_response_payload(
				$execution,
				array(
					'status'      => 'executed',
					'proposal_id' => $proposal_id,
				),
				$this->request_wants_full_execution_detail( $request )
			),
			200
		);
	}

	/**
	 * Approves a pending proposal through Core and executes supported input.
	 *
	 * Reserved for WordPress administrator sessions: this action holds approval
	 * authority. Signed local clients must use the two-step flow instead (human
	 * approval in the Core admin, then POST /proposals/{id}/execute).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function approve_and_execute_proposal_route( WP_REST_Request $request ) {
		$started   = microtime( true );
		$body_size = $this->validate_request_body_size( $request, self::MAX_LIGHT_POST_BODY_BYTES );
		if ( is_wp_error( $body_size ) ) {
			$this->emit_operation_event( 'adapter.proposal.execute', $started, $body_size );
			return $body_size;
		}

		$proposal_id   = sanitize_text_field( (string) $request->get_param( 'proposal_id' ) );
		$event_context = $this->observability_request_context( $request, array( 'proposal_id' => $proposal_id ) );
		if ( '' === $proposal_id ) {
			$error = new WP_Error(
				'npcink_openclaw_adapter_proposal_id_required',
				__( 'proposal_id is required.', 'npcink-ai-client-adapter' ),
				array( 'status' => 400 )
			);
			$this->emit_operation_event( 'adapter.proposal.execute', $started, $error, $event_context );
			return $error;
		}

		if ( $this->current_signed_authenticated ) {
			$error = new WP_Error(
				'npcink_openclaw_adapter_approve_requires_admin_session',
				__( 'The unified approve-and-execute action requires a WordPress administrator session. Signed AI clients must wait for human approval in the Npcink Governance Core admin, then call POST /proposals/{proposal_id}/execute.', 'npcink-ai-client-adapter' ),
				array(
					'status'            => 403,
					'operator_feedback' => array(
						'reason'             => 'signed_client_cannot_self_approve',
						'next_step'          => 'Approve the proposal in the Npcink Governance Core admin, then call POST /proposals/{proposal_id}/execute from the same signed client.',
						'authorized_surface' => 'wordpress_admin_session_only',
					),
				)
			);
			$this->emit_operation_event( 'adapter.proposal.execute', $started, $error, $event_context );
			return $error;
		}

		$proposal = $this->get_core_proposal_data( $proposal_id );
		if ( is_wp_error( $proposal ) ) {
			$this->emit_operation_event( 'adapter.proposal.execute', $started, $proposal, $event_context );
			return $proposal;
		}

		$existing_record = $this->completed_execution_record( $proposal_id );
		if ( is_array( $existing_record ) ) {
			$error = $this->execution_already_completed_error( $proposal_id, $existing_record );
			$this->emit_operation_event( 'adapter.proposal.execute', $started, $error, $event_context );
			return $error;
		}

		$ability_id                  = sanitize_text_field( (string) ( $proposal['ability_id'] ?? '' ) );
		$event_context['ability_id'] = $ability_id;
		$execution_actions           = $this->normalize_execution_actions( $proposal_id, $proposal );
		if ( is_wp_error( $execution_actions ) ) {
			$this->emit_operation_event( 'adapter.proposal.execute', $started, $execution_actions, $event_context );
			return $execution_actions;
		}

		$status_before       = sanitize_key( (string) ( $proposal['status'] ?? '' ) );
		$approved_by_adapter = false;

		if ( 'pending' === $status_before ) {
			$note = sanitize_text_field( (string) $request->get_param( 'note' ) );
			if ( '' === $note ) {
				$note = __( 'Approved by Npcink AI Client Adapter approve-and-execute.', 'npcink-ai-client-adapter' );
			}

			$approved_response = $this->dispatch_upstream(
				'POST',
				'/npcink-governance-core/v1/proposals/' . rawurlencode( $proposal_id ) . '/approve',
				array( 'note' => $note ),
				false,
				false,
				false
			);
			if ( is_wp_error( $approved_response ) ) {
				$this->emit_operation_event( 'adapter.proposal.execute', $started, $approved_response, $event_context );
				return $approved_response;
			}

			$approved = $approved_response->get_data();
			if ( ! is_array( $approved ) || 'approved' !== (string) ( $approved['status'] ?? '' ) ) {
				$error = new WP_Error(
					'npcink_openclaw_adapter_core_approve_failed',
					__( 'Core did not return an approved proposal state.', 'npcink-ai-client-adapter' ),
					array(
						'status'      => 409,
						'proposal_id' => $proposal_id,
						'core_result' => $approved,
					),
				);
				$this->emit_operation_event( 'adapter.proposal.execute', $started, $error, $event_context );
				return $error;
			}

			$approved_by_adapter = true;
		} elseif ( 'approved' !== $status_before ) {
			$code  = 'rejected' === $status_before ? 'npcink_openclaw_adapter_proposal_rejected' : 'npcink_openclaw_adapter_proposal_not_executable';
			$error = new WP_Error(
				$code,
				__( 'This proposal cannot be approved and executed from its current status.', 'npcink-ai-client-adapter' ),
				array(
					'status'            => 409,
					'proposal_id'       => $proposal_id,
					'ability_id'        => $ability_id,
					'status_before'     => $status_before,
					'operator_feedback' => $this->proposal_status_operator_feedback( $proposal, $status_before ),
				)
			);
			$this->emit_operation_event( 'adapter.proposal.execute', $started, $error, $event_context );
			return $error;
		}

		$execution = $this->execute_core_approved_proposal( $request, $proposal_id, $proposal );
		if ( is_wp_error( $execution ) ) {
			$this->emit_operation_event( 'adapter.proposal.execute', $started, $execution, $event_context );
			return $execution;
		}

		$this->emit_operation_event(
			'adapter.proposal.execute',
			$started,
			null,
			array_merge(
				$event_context,
				array(
					'correlation_id'     => (string) ( $execution['correlation_id'] ?? '' ),
					'adapter_request_id' => (string) ( $execution['adapter_request_id'] ?? '' ),
					'executed_count'     => (int) ( $execution['executed_count'] ?? 0 ),
					'failed_count'       => (int) ( $execution['failed_count'] ?? 0 ),
				)
			)
		);

		return new WP_REST_Response(
			$this->public_execution_response_payload(
				$execution,
				array(
					'success'             => true,
					'proposal_id'         => $proposal_id,
					'status_before'       => $status_before,
					'approved_by_adapter' => $approved_by_adapter,
				),
				$this->request_wants_full_execution_detail( $request )
			),
			200
		);
	}

	/**
	 * Returns whether the caller explicitly requested full execution detail.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private function request_wants_full_execution_detail( WP_REST_Request $request ): bool {
		return 'full' === sanitize_key( (string) $request->get_param( 'detail' ) )
			|| $this->boolean_input_value( $request->get_param( 'debug_detail' ) );
	}

	/**
	 * Returns whether a request value is an explicit true boolean.
	 *
	 * @param mixed $value Input value.
	 * @return bool
	 */
	private function boolean_input_value( $value ): bool {
		if ( true === $value ) {
			return true;
		}

		if ( is_int( $value ) ) {
			return 1 === $value;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return false;
	}

	/**
	 * Builds the public response for Adapter final execution routes.
	 *
	 * @param array<string,mixed> $execution Execution result.
	 * @param array<string,mixed> $extra Extra top-level fields.
	 * @param bool                $include_detail Whether to include raw execution detail.
	 * @return array<string,mixed>
	 */
	private function public_execution_response_payload( array $execution, array $extra, bool $include_detail ): array {
		$payload = array_merge(
			$extra,
			array(
				'correlation_id'                  => (string) ( $execution['correlation_id'] ?? '' ),
				'ability_id'                      => (string) ( $execution['ability_id'] ?? '' ),
				'post_id'                         => absint( $execution['post_id'] ?? 0 ),
				'post_ids'                        => array_values( array_map( 'absint', is_array( $execution['post_ids'] ?? null ) ? $execution['post_ids'] : array() ) ),
				'execution_mode'                  => sanitize_key( (string) ( $execution['execution_mode'] ?? '' ) ),
				'adapter_request_id'              => sanitize_text_field( (string) ( $execution['adapter_request_id'] ?? '' ) ),
				'preflight_source'                => sanitize_key( (string) ( $execution['preflight_source'] ?? '' ) ),
				'commit_execution'                => false,
				'core_commit_execution'           => false,
				'execution_surface'               => 'wp_abilities_rest',
				'execution_handoff_posture'       => $this->execution_handoff_posture(),
				'selected_count'                  => absint( $execution['selected_count'] ?? 0 ),
				'submitted_count'                 => absint( $execution['submitted_count'] ?? 0 ),
				'executed_count'                  => absint( $execution['executed_count'] ?? 0 ),
				'failed_count'                    => absint( $execution['failed_count'] ?? 0 ),
				'blocked_count'                   => absint( $execution['blocked_count'] ?? 0 ),
				'partial_success'                 => (bool) ( $execution['partial_success'] ?? false ),
				'retryable'                       => (bool) ( $execution['retryable'] ?? false ),
				'operator_next_action'            => sanitize_key( (string) ( $execution['operator_next_action'] ?? '' ) ),
				'batch_review_feedback'           => is_array( $execution['batch_review_feedback'] ?? null ) ? $execution['batch_review_feedback'] : array(),
				'core_preflight_evidence'         => is_array( $execution['core_preflight_evidence'] ?? null ) ? $execution['core_preflight_evidence'] : array(),
				'implementation_posture_evidence' => is_array( $execution['implementation_posture_evidence'] ?? null ) ? $execution['implementation_posture_evidence'] : array(),
				'media_alt_live_preflight'        => is_array( $execution['media_alt_live_preflight'] ?? null ) ? $execution['media_alt_live_preflight'] : array(),
				'execution_record'                => is_array( $execution['execution_record'] ?? null ) ? $execution['execution_record'] : array(),
				'approval_context'                => is_array( $execution['approval_context'] ?? null ) ? $execution['approval_context'] : array(),
				'execution_detail_included'       => $include_detail,
			)
		);

		$payload['results']   = is_array( $execution['results'] ?? null ) ? $execution['results'] : array();
		$payload['result']    = is_array( $execution['result'] ?? null ) ? $execution['result'] : array();
		$payload['execution'] = array(
			'success'                  => true,
			'post_status_before'       => (string) ( $execution['post_status_before'] ?? '' ),
			'post_status_after'        => (string) ( $execution['post_status_after'] ?? '' ),
			'selected_count'           => absint( $execution['selected_count'] ?? 0 ),
			'submitted_count'          => absint( $execution['submitted_count'] ?? 0 ),
			'executed_count'           => absint( $execution['executed_count'] ?? 0 ),
			'failed_count'             => absint( $execution['failed_count'] ?? 0 ),
			'blocked_count'            => absint( $execution['blocked_count'] ?? 0 ),
			'partial_success'          => (bool) ( $execution['partial_success'] ?? false ),
			'retryable'                => (bool) ( $execution['retryable'] ?? false ),
			'operator_next_action'     => sanitize_key( (string) ( $execution['operator_next_action'] ?? '' ) ),
			'media_alt_live_preflight' => $payload['media_alt_live_preflight'],
			'result'                   => $payload['result'],
			'results'                  => $payload['results'],
		);

		if ( $include_detail ) {
			$payload['preflight'] = is_array( $execution['preflight'] ?? null ) ? $execution['preflight'] : array();
		}

		return $payload;
	}

	/**
	 * Fetches a Core proposal and validates the response shape.
	 *
	 * @param string $proposal_id Proposal id.
	 * @return array<string,mixed>|WP_Error
	 */
	private function get_core_proposal_data( string $proposal_id ) {
		$proposal_response = $this->dispatch_upstream( 'GET', '/npcink-governance-core/v1/proposals/' . rawurlencode( $proposal_id ) );
		if ( is_wp_error( $proposal_response ) ) {
			return $proposal_response;
		}

		$proposal = $proposal_response->get_data();
		if ( ! is_array( $proposal ) ) {
			return new WP_Error(
				'npcink_openclaw_adapter_invalid_core_proposal',
				__( 'Core proposal response is invalid.', 'npcink-ai-client-adapter' ),
				array( 'status' => 502 )
			);
		}

		return $proposal;
	}

	/**
	 * Builds a flat output map for later batch actions.
	 *
	 * @param array<string,mixed> $result Executed action result.
	 * @return array<string,mixed>
	 */
	private function output_map_from_action_result( array $result ): array {
		$output = is_array( $result['result'] ?? null ) ? $result['result'] : array();
		foreach ( array( 'post_id', 'ability_id', 'target_ability_id', 'post_status_before', 'post_status_after' ) as $field ) {
			if ( array_key_exists( $field, $result ) ) {
				$output[ $field ] = $result[ $field ];
			}
		}
		return $output;
	}

	/**
	 * Normalizes one proposal into concrete Adapter execution actions.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param array<string,mixed> $proposal Core proposal.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function normalize_execution_actions( string $proposal_id, array $proposal ) {
		$proposal_ability_id = sanitize_text_field( (string) ( $proposal['ability_id'] ?? '' ) );
		$input               = is_array( $proposal['input'] ?? null ) ? $proposal['input'] : array();
		$write_actions       = is_array( $input['write_actions'] ?? null ) ? array_values( $input['write_actions'] ) : array();
		$has_write_actions   = ! empty( $write_actions );
		$top_level_post_id   = absint( $input['post_id'] ?? 0 );

		if ( $has_write_actions && $top_level_post_id > 0 ) {
			return new WP_Error(
				'npcink_openclaw_adapter_execution_input_ambiguous',
				__( 'Proposal input must use either post_id or write_actions, not both.', 'npcink-ai-client-adapter' ),
				array(
					'status'      => 400,
					'proposal_id' => $proposal_id,
				)
			);
		}

		if ( $has_write_actions ) {
			if ( count( $write_actions ) > self::MAX_EXECUTION_ACTIONS ) {
				return new WP_Error(
					'npcink_openclaw_adapter_write_actions_limit_exceeded',
					__( 'Proposal write_actions exceeds the adapter execution limit.', 'npcink-ai-client-adapter' ),
					array(
						'status'      => 400,
						'proposal_id' => $proposal_id,
						'max_actions' => self::MAX_EXECUTION_ACTIONS,
					)
				);
			}

			$actions           = array();
			$available_outputs = array();
			foreach ( $write_actions as $index => $raw_action ) {
				if ( ! is_array( $raw_action ) ) {
					return new WP_Error(
						'npcink_openclaw_adapter_write_action_invalid',
						__( 'Each write_actions item must be an object.', 'npcink-ai-client-adapter' ),
						array(
							'status'       => 400,
							'proposal_id'  => $proposal_id,
							'action_index' => $index,
						)
					);
				}

				$target_ability_id = sanitize_text_field( (string) ( $raw_action['target_ability_id'] ?? '' ) );
				if ( '' === $target_ability_id ) {
					return new WP_Error(
						'npcink_openclaw_adapter_write_action_target_required',
						__( 'Each write_actions item must include target_ability_id.', 'npcink-ai-client-adapter' ),
						array(
							'status'       => 400,
							'proposal_id'  => $proposal_id,
							'action_index' => $index,
						)
					);
				}

				$allowed = $this->execution_input_validator->validate_execute_ability( $proposal_id, $target_ability_id );
				if ( is_wp_error( $allowed ) ) {
					$allowed->add_data(
						array_merge(
							(array) $allowed->get_error_data(),
							array(
								'action_index'      => $index,
								'action_id'         => sanitize_key( (string) ( $raw_action['action_id'] ?? '' ) ),
								'target_ability_id' => $target_ability_id,
							)
						)
					);
					return $allowed;
				}

				$action_id = sanitize_key( (string) ( $raw_action['action_id'] ?? '' ) );
				if ( '' === $action_id ) {
					$action_id = 'action-' . ( $index + 1 );
				}
				if ( isset( $available_outputs[ $action_id ] ) ) {
					return new WP_Error(
						'npcink_openclaw_adapter_write_action_duplicate_id',
						__( 'Each write_actions item must have a unique action_id.', 'npcink-ai-client-adapter' ),
						array(
							'status'       => 400,
							'proposal_id'  => $proposal_id,
							'action_index' => $index,
							'action_id'    => $action_id,
						)
					);
				}

				$action_input = is_array( $raw_action['input'] ?? null ) ? $raw_action['input'] : array();
				$valid_refs   = $this->execution_input_validator->validate_output_references( $proposal_id, $action_input, $available_outputs, $index );
				if ( is_wp_error( $valid_refs ) ) {
					return $valid_refs;
				}

				$post_id     = absint( $action_input['post_id'] ?? 0 );
				$valid_input = $this->execution_input_validator->validate_execute_action_input( $proposal_id, $target_ability_id, $action_input, $post_id, $index, true, true );
				if ( is_wp_error( $valid_input ) ) {
					return $valid_input;
				}

				if ( array_key_exists( 'requires_approval', $raw_action ) && true !== (bool) $raw_action['requires_approval'] ) {
					return new WP_Error(
						'npcink_openclaw_adapter_write_action_approval_required',
						__( 'Each executable write action must require Core approval.', 'npcink-ai-client-adapter' ),
						array(
							'status'       => 409,
							'proposal_id'  => $proposal_id,
							'action_index' => $index,
						)
					);
				}

				if ( array_key_exists( 'core_proxy_execute', $raw_action ) && false !== (bool) $raw_action['core_proxy_execute'] ) {
					return new WP_Error(
						'npcink_openclaw_adapter_write_action_core_proxy_execute_unsupported',
						__( 'Write actions must keep core_proxy_execute=false before Adapter execution.', 'npcink-ai-client-adapter' ),
						array(
							'status'       => 409,
							'proposal_id'  => $proposal_id,
							'action_index' => $index,
						)
					);
				}

				if ( array_key_exists( 'commit_execution', $raw_action ) && false !== (bool) $raw_action['commit_execution'] ) {
					return new WP_Error(
						'npcink_openclaw_adapter_write_action_commit_execution_unsupported',
						__( 'Write actions must keep commit_execution=false before Adapter execution.', 'npcink-ai-client-adapter' ),
						array(
							'status'       => 409,
							'proposal_id'  => $proposal_id,
							'action_index' => $index,
						)
					);
				}

				$requires_input = is_array( $raw_action['requires_input'] ?? null ) ? array_values( $raw_action['requires_input'] ) : array();
				if ( ! empty( $requires_input ) ) {
					return new WP_Error(
						'npcink_openclaw_adapter_write_action_needs_input',
						__( 'Write action still requires reviewed input before execution.', 'npcink-ai-client-adapter' ),
						array(
							'status'         => 409,
							'proposal_id'    => $proposal_id,
							'action_index'   => $index,
							'requires_input' => $requires_input,
						)
					);
				}

				$preflight_blockers = is_array( $raw_action['preflight_blockers'] ?? null ) ? array_values( $raw_action['preflight_blockers'] ) : array();
				if ( ( array_key_exists( 'proposal_ready', $raw_action ) && false === (bool) $raw_action['proposal_ready'] ) || ! empty( $preflight_blockers ) ) {
					return new WP_Error(
						'npcink_openclaw_adapter_write_action_not_ready',
						__( 'Write action is not marked ready for execution.', 'npcink-ai-client-adapter' ),
						array(
							'status'             => 409,
							'proposal_id'        => $proposal_id,
							'action_index'       => $index,
							'preflight_blockers' => $preflight_blockers,
						)
					);
				}

				$actions[]                       = array(
					'action_id'         => $action_id,
					'action_index'      => $index,
					'ability_id'        => $target_ability_id,
					'target_ability_id' => $target_ability_id,
					'execution_profile' => $this->execution_action_runner->profile_id( $target_ability_id ),
					'idempotency_key'   => $this->execution_action_runner->idempotency_key( $proposal_id, $action_id, $action_input ),
					'post_id'           => $post_id,
					'input'             => $action_input,
					'execution_mode'    => 'batch_write_actions',
				);
				$available_outputs[ $action_id ] = true;
			}

			return $actions;
		}

		$allowed = $this->execution_input_validator->validate_execute_ability( $proposal_id, $proposal_ability_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$valid_input = $this->execution_input_validator->validate_execute_action_input( $proposal_id, $proposal_ability_id, $input, $top_level_post_id, null, false, true );
		if ( is_wp_error( $valid_input ) ) {
			return $valid_input;
		}

		return array(
			array(
				'action_id'         => 'single-post',
				'action_index'      => 0,
				'ability_id'        => $proposal_ability_id,
				'target_ability_id' => $proposal_ability_id,
				'execution_profile' => $this->execution_action_runner->profile_id( $proposal_ability_id ),
				'idempotency_key'   => $this->execution_action_runner->idempotency_key( $proposal_id, 'single-post', $input ),
				'post_id'           => $top_level_post_id,
				'input'             => $input,
				'execution_mode'    => 'single_post',
			),
		);
	}

	/**
	 * Builds a public selected-batch execution summary.
	 *
	 * @param array<int,array<string,mixed>> $actions Normalized actions.
	 * @param array<int,array<string,mixed>> $results Executed results.
	 * @param array<string,mixed>|null       $failed_action Failed action.
	 * @return array<string,mixed>
	 */
	private function selected_batch_execution_summary( array $actions, array $results, ?array $failed_action = null ): array {
		$selected_count  = count( $actions );
		$executed_count  = count( $results );
		$failed_count    = is_array( $failed_action ) ? 1 : 0;
		$blocked_count   = max( 0, $selected_count - $executed_count - $failed_count );
		$partial_success = $executed_count > 0 && $failed_count > 0;

		return array(
			'selected_count'       => $selected_count,
			'submitted_count'      => $selected_count,
			'executed_count'       => $executed_count,
			'failed_count'         => $failed_count,
			'blocked_count'        => $blocked_count,
			'partial_success'      => $partial_success,
			'retryable'            => false,
			'operator_next_action' => $partial_success ? 'review_partial_failure_and_create_revised_proposal' : ( $failed_count > 0 ? 'review_failed_execution_and_create_revised_proposal' : 'review_execution_result' ),
		);
	}

	/**
	 * Rechecks a governed missing-ALT write against live WordPress state.
	 *
	 * Core owns approval and preserves the reviewed evidence. Adapter validates
	 * that handoff and asks Toolkit to dry-run the exact approved input directly
	 * before commit, so Toolkit remains the live attachment truth.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param array<string,mixed> $action Normalized action.
	 * @param array<string,mixed> $preflight Core commit preflight.
	 * @param array<string,mixed> $approval_context Core approval context.
	 * @param string              $correlation_id Correlation id.
	 * @param array<string,mixed> $base_request_context Base request context.
	 * @return array<string,mixed>|WP_Error
	 */
	private function media_alt_live_preflight( string $proposal_id, array $action, array $preflight, array $approval_context, string $correlation_id, array $base_request_context ) {
		$item_preflight = is_array( $preflight['proposal_item_preflight'] ?? null ) ? $preflight['proposal_item_preflight'] : array();
		$guard          = is_array( $item_preflight['media_alt_guard'] ?? null ) ? $item_preflight['media_alt_guard'] : array();
		if ( empty( $guard['applies'] ) ) {
			return array();
		}

		$ability_id = sanitize_text_field( (string) ( $action['ability_id'] ?? '' ) );
		$input      = is_array( $action['input'] ?? null ) ? $action['input'] : array();
		$allowed    = array_fill_keys( array( 'attachment_id', 'alt', 'expected_current_alt', 'operator_visual_review_confirmed', 'dry_run', 'commit', 'idempotency_key' ), true );
		$valid      = true === ( $guard['valid'] ?? false )
			&& true === ( $guard['requires_live_value_check'] ?? false )
			&& 'adapter_toolkit_dry_run_before_commit' === (string) ( $guard['live_value_check_owner'] ?? '' )
			&& 'media_alt_apply_plan.v1' === (string) ( $guard['contract_version'] ?? '' )
			&& 'npcink-abilities-toolkit/update-media-details' === $ability_id
			&& absint( $input['attachment_id'] ?? 0 ) > 0
			&& absint( $input['attachment_id'] ?? 0 ) === absint( $guard['attachment_id'] ?? 0 )
			&& array_key_exists( 'expected_current_alt', $input )
			&& '' === (string) $input['expected_current_alt']
			&& true === ( $input['operator_visual_review_confirmed'] ?? false )
			&& '' !== trim( sanitize_text_field( (string) ( $input['alt'] ?? '' ) ) )
			&& '' !== trim( sanitize_text_field( (string) ( $input['idempotency_key'] ?? '' ) ) );
		foreach ( array_keys( $input ) as $key ) {
			if ( ! isset( $allowed[ (string) $key ] ) ) {
				$valid = false;
				break;
			}
		}

		if ( ! $valid ) {
			return new WP_Error(
				'npcink_openclaw_adapter_media_alt_guard_invalid',
				__( 'The Core media ALT guard does not match the approved ALT-only input.', 'npcink-ai-client-adapter' ),
				array(
					'status'               => 409,
					'proposal_id'          => $proposal_id,
					'operator_next_action' => 'review_media_alt_proposal_and_create_revised_proposal',
				)
			);
		}

		$dry_run_input            = $input;
		$dry_run_input['dry_run'] = true;
		$dry_run_input['commit']  = false;
		$context                  = array_merge(
			$approval_context,
			$base_request_context,
			array(
				'ability_id'        => $ability_id,
				'target_ability_id' => $ability_id,
				'proposal_id'       => $proposal_id,
				'correlation_id'    => $correlation_id,
				'via'               => 'npcink-ai-client-adapter-media-alt-live-preflight',
			)
		);
		$route                    = '/wp-abilities/v1/abilities/' . $ability_id . '/run';
		$response                 = $this->dispatch_upstream_with_runtime_context( $context, 'POST', $route, array( 'input' => $dry_run_input ), false, true );
		if ( is_wp_error( $response ) ) {
			$data         = $response->get_error_data();
			$data         = is_array( $data ) ? $data : array();
			$error_status = absint( $data['status'] ?? 409 );
			$response->add_data(
				array_merge(
					$data,
					array(
						'status'               => $error_status > 0 ? $error_status : 409,
						'proposal_id'          => $proposal_id,
						'media_alt_live_check' => 'failed',
						'operator_next_action' => 'refresh_media_alt_review_and_create_revised_proposal',
					)
				)
			);
			return $response;
		}

		return array(
			'checked'                   => true,
			'contract_version'          => 'media_alt_apply_plan.v1',
			'attachment_id'             => absint( $input['attachment_id'] ?? 0 ),
			'expected_current_alt'      => '',
			'visual_review_confirmed'   => true,
			'toolkit_dry_run_succeeded' => true,
			'live_value_check_owner'    => 'adapter_toolkit_dry_run_before_commit',
		);
	}

	/**
	 * Runs a bounded readback after approved block writes.
	 *
	 * @param string              $ability_id Executed write ability id.
	 * @param array<string,mixed> $ability_input Executed input.
	 * @param array<string,mixed> $ability_result Write ability result.
	 * @param array<string,mixed> $base_request_context Base request context.
	 * @return array<string,mixed>
	 */
	private function block_write_readback_verification( string $ability_id, array $ability_input, array $ability_result, array $base_request_context ): array {
		$read_ability_id = '';
		$read_input      = array();

		if ( 'npcink-abilities-toolkit/update-post-blocks' === $ability_id ) {
			$post_id = absint( $ability_result['post_id'] ?? ( $ability_input['post_id'] ?? 0 ) );
			if ( $post_id <= 0 ) {
				return array();
			}
			$read_ability_id = 'npcink-abilities-toolkit/get-post-blocks';
			$read_input      = array(
				'post_id'              => $post_id,
				'include_inner_blocks' => true,
			);
		} elseif ( in_array( $ability_id, array( 'npcink-abilities-toolkit/update-template-blocks', 'npcink-abilities-toolkit/upsert-template-blocks' ), true ) ) {
			$post_id = absint( $ability_result['post_id'] ?? ( $ability_input['post_id'] ?? 0 ) );
			$slug    = sanitize_key( (string) ( $ability_result['slug'] ?? ( $ability_input['slug'] ?? '' ) ) );
			if ( $post_id <= 0 && '' === $slug ) {
				return array();
			}
			$read_ability_id = 'npcink-abilities-toolkit/get-template-blocks';
			$read_input      = $post_id > 0 ? array( 'post_id' => $post_id ) : array( 'slug' => $slug );
		} elseif ( 'npcink-abilities-toolkit/update-template-part-blocks' === $ability_id ) {
			$post_id = absint( $ability_result['post_id'] ?? ( $ability_input['post_id'] ?? 0 ) );
			$slug    = sanitize_key( (string) ( $ability_result['slug'] ?? ( $ability_input['slug'] ?? '' ) ) );
			if ( $post_id <= 0 && '' === $slug ) {
				return array();
			}
			$read_ability_id = 'npcink-abilities-toolkit/get-template-part-blocks';
			$read_input      = $post_id > 0 ? array( 'post_id' => $post_id ) : array( 'slug' => $slug );
		}

		if ( '' === $read_ability_id ) {
			return array();
		}

		$read_context                        = $base_request_context;
		$read_context['verification_source'] = 'post_execution_block_readback';
		$read_context['write_ability_id']    = $ability_id;
		$read_context['ability_id']          = $read_ability_id;
		$response                            = $this->run_read_ability( $read_ability_id, $read_input, $read_context );
		if ( is_wp_error( $response ) ) {
			$error_data = $response->get_error_data();
			$error_data = is_array( $error_data ) ? $error_data : array();

			return array(
				'block_readback_status'      => 'readback_failed',
				'block_readback_ability_id'  => $read_ability_id,
				'block_readback_error_code'  => sanitize_key( $response->get_error_code() ),
				'block_readback_status_code' => absint( $error_data['status'] ?? 0 ),
			);
		}

		$data        = $response->get_data();
		$data        = is_array( $data ) ? $data : array();
		$read_result = is_array( $data['result'] ?? null ) ? $data['result'] : array();
		$validation  = is_array( $ability_result['validation'] ?? null ) ? $ability_result['validation'] : array();

		return array(
			'block_readback_status'         => 'verified',
			'block_readback_ability_id'     => $read_ability_id,
			'block_readback_post_id'        => absint( $read_result['post_id'] ?? ( $read_input['post_id'] ?? 0 ) ),
			'block_readback_post_type'      => sanitize_key( (string) ( $read_result['post_type'] ?? ( $ability_result['post_type'] ?? '' ) ) ),
			'block_readback_slug'           => sanitize_key( (string) ( $read_result['slug'] ?? ( $ability_result['slug'] ?? ( $read_input['slug'] ?? '' ) ) ) ),
			'block_readback_block_count'    => absint( $read_result['block_count'] ?? 0 ),
			'block_readback_content_length' => absint( $read_result['content_length'] ?? 0 ),
			'block_write_block_count_after' => absint( $ability_result['block_count_after'] ?? 0 ),
			'block_write_validation_valid'  => (bool) ( $validation['valid'] ?? false ),
			'block_write_roundtrip_checked' => (bool) ( $validation['roundtrip_checked'] ?? false ),
			'block_write_roundtrip_ok'      => (bool) ( $validation['roundtrip_ok'] ?? false ),
		);
	}

	/**
	 * Executes an approved proposal after Core commit preflight.
	 *
	 * @param WP_REST_Request     $request Request.
	 * @param string              $proposal_id Proposal id.
	 * @param array<string,mixed> $proposal Core proposal.
	 * @return array<string,mixed>|WP_Error
	 */
	private function execute_core_approved_proposal( WP_REST_Request $request, string $proposal_id, array $proposal ) {
		$lock_key = $this->acquire_execution_lock( $proposal_id );
		if ( is_wp_error( $lock_key ) ) {
			return $lock_key;
		}

		try {
			return $this->execute_core_approved_proposal_locked( $request, $proposal_id, $proposal );
		} finally {
			$this->release_execution_lock( $lock_key );
		}
	}

	/**
	 * Executes an approved proposal after the per-proposal lock is held.
	 *
	 * @param WP_REST_Request     $request Request.
	 * @param string              $proposal_id Proposal id.
	 * @param array<string,mixed> $proposal Core proposal.
	 * @return array<string,mixed>|WP_Error
	 */
	private function execute_core_approved_proposal_locked( WP_REST_Request $request, string $proposal_id, array $proposal ) {
		$proposal_ability_id = sanitize_text_field( (string) ( $proposal['ability_id'] ?? '' ) );
		$existing_record     = $this->completed_execution_record( $proposal_id );
		if ( is_array( $existing_record ) ) {
			return $this->execution_already_completed_error( $proposal_id, $existing_record );
		}

		$actions = $this->normalize_execution_actions( $proposal_id, $proposal );
		if ( is_wp_error( $actions ) ) {
			return $actions;
		}

		$preflight        = $this->consume_cached_preflight_handoff( $proposal_id, $proposal );
		$preflight_source = is_array( $preflight ) ? 'adapter_cached_handoff' : 'core_commit_preflight';
		if ( ! is_array( $preflight ) ) {
			$preflight_response = $this->dispatch_upstream( 'POST', '/npcink-governance-core/v1/proposals/' . rawurlencode( $proposal_id ) . '/commit-preflight' );
			if ( is_wp_error( $preflight_response ) ) {
				return $this->error_with_operator_feedback( $preflight_response, $this->preflight_operator_feedback( $preflight_response, $proposal ) );
			}

			$preflight = $preflight_response->get_data();
			if ( ! is_array( $preflight ) ) {
				return new WP_Error(
					'npcink_openclaw_adapter_invalid_core_preflight',
					__( 'Core commit preflight response is invalid.', 'npcink-ai-client-adapter' ),
					array( 'status' => 502 )
				);
			}
		}
		$preflight['adapter_preflight_source'] = $preflight_source;

		$approval_context = is_array( $preflight['approval_context'] ?? null ) ? $preflight['approval_context'] : array();
		if ( true !== (bool) ( $approval_context['approval_commit_authorized'] ?? false ) ) {
			return new WP_Error(
				'npcink_openclaw_adapter_preflight_not_authorized',
				__( 'Core commit preflight did not authorize approval commit.', 'npcink-ai-client-adapter' ),
				array(
					'status'            => 409,
					'proposal_id'       => $proposal_id,
					'preflight'         => $preflight,
					'operator_feedback' => $this->preflight_operator_feedback( null, $proposal, $preflight ),
				)
			);
		}

		if ( false !== (bool) ( $preflight['commit_execution'] ?? true ) ) {
			return new WP_Error(
				'npcink_openclaw_adapter_core_execution_unsupported',
				__( 'Core commit preflight must not execute final writes.', 'npcink-ai-client-adapter' ),
				array(
					'status'      => 409,
					'proposal_id' => $proposal_id,
					'preflight'   => $preflight,
				)
			);
		}

		if ( false === (bool) ( $preflight['proposal_item_preflight']['executable'] ?? true ) ) {
			return new WP_Error(
				'npcink_openclaw_adapter_preflight_item_blocked',
				__( 'Core commit preflight did not mark the proposal item executable.', 'npcink-ai-client-adapter' ),
				array(
					'status'            => 409,
					'proposal_id'       => $proposal_id,
					'preflight'         => $preflight,
					'operator_feedback' => $this->preflight_operator_feedback( null, $proposal, $preflight ),
				)
			);
		}

		$correlation_id = sanitize_text_field( (string) ( $preflight['correlation_id'] ?? ( $approval_context['correlation_id'] ?? '' ) ) );
		if ( '' === $correlation_id ) {
			return new WP_Error(
				'npcink_openclaw_adapter_preflight_correlation_required',
				__( 'Core commit preflight did not return a correlation id.', 'npcink-ai-client-adapter' ),
				array(
					'status'      => 409,
					'proposal_id' => $proposal_id,
					'preflight'   => $preflight,
				)
			);
		}

		$binding_error = $this->validate_preflight_binding( $proposal_id, $proposal, $preflight );
		if ( is_wp_error( $binding_error ) ) {
			return $binding_error;
		}
		$implementation_posture_evidence = $this->implementation_posture_execution_evidence( $proposal_id, $actions, $preflight );
		if ( is_wp_error( $implementation_posture_evidence ) ) {
			return $implementation_posture_evidence;
		}
		$preflight['implementation_posture_evidence'] = $implementation_posture_evidence;

		$base_request_context                           = $this->request_log_context( $request, '' !== $proposal_ability_id ? $proposal_ability_id : (string) ( $actions[0]['ability_id'] ?? '' ) );
		$base_request_context['proposal_id']            = $proposal_id;
		$base_request_context['correlation_id']         = $correlation_id;
		$npcink_governance_core                         = is_array( $base_request_context['npcink_governance_core'] ?? null ) ? $base_request_context['npcink_governance_core'] : array();
		$npcink_governance_core['proposal_id']          = $proposal_id;
		$npcink_governance_core['correlation_id']       = $correlation_id;
		$base_request_context['npcink_governance_core'] = $npcink_governance_core;

		$results = array();
		$outputs = array();
		foreach ( $actions as $action ) {
			$action_index   = absint( $action['action_index'] ?? 0 );
			$resolved_input = $this->execution_input_validator->resolve_output_references(
				is_array( $action['input'] ?? null ) ? $action['input'] : array(),
				$outputs,
				$proposal_id,
				$action_index
			);
			if ( is_wp_error( $resolved_input ) ) {
				$execution_summary = $this->selected_batch_execution_summary( $actions, $results, $action );
				$execution_record  = $this->store_failed_execution_record(
					$proposal_id,
					$proposal,
					$actions,
					$results,
					$preflight,
					$correlation_id,
					sanitize_text_field( (string) ( $base_request_context['adapter_request_id'] ?? '' ) ),
					$resolved_input,
					$action
				);
				$resolved_input->add_data(
					array_merge(
						(array) $resolved_input->get_error_data(),
						array(
							'correlation_id'       => $correlation_id,
							'action_id'            => sanitize_key( (string) ( $action['action_id'] ?? '' ) ),
							'action_index'         => $action_index,
							'execution_profile'    => sanitize_text_field( (string) ( $action['execution_profile'] ?? '' ) ),
							'idempotency_key'      => sanitize_text_field( (string) ( $action['idempotency_key'] ?? '' ) ),
							'selected_count'       => $execution_summary['selected_count'],
							'submitted_count'      => $execution_summary['submitted_count'],
							'executed_count'       => $execution_summary['executed_count'],
							'failed_count'         => $execution_summary['failed_count'],
							'blocked_count'        => $execution_summary['blocked_count'],
							'partial_success'      => $execution_summary['partial_success'],
							'retryable'            => $execution_summary['retryable'],
							'operator_next_action' => $execution_summary['operator_next_action'],
							'executed_results'     => $results,
							'execution_record'     => $execution_record,
						)
					)
				);
				return $resolved_input;
			}

			$action['input']   = is_array( $resolved_input ) ? $resolved_input : array();
			$action['post_id'] = absint( $action['input']['post_id'] ?? 0 );
			$valid_input       = $this->execution_input_validator->validate_execute_action_input(
				$proposal_id,
				sanitize_text_field( (string) ( $action['ability_id'] ?? '' ) ),
				$action['input'],
				absint( $action['post_id'] ?? 0 ),
				$action_index,
				false,
				true
			);
			if ( is_wp_error( $valid_input ) ) {
				$execution_summary = $this->selected_batch_execution_summary( $actions, $results, $action );
				$execution_record  = $this->store_failed_execution_record(
					$proposal_id,
					$proposal,
					$actions,
					$results,
					$preflight,
					$correlation_id,
					sanitize_text_field( (string) ( $base_request_context['adapter_request_id'] ?? '' ) ),
					$valid_input,
					$action
				);
				$valid_input->add_data(
					array_merge(
						(array) $valid_input->get_error_data(),
						array(
							'correlation_id'       => $correlation_id,
							'action_id'            => sanitize_key( (string) ( $action['action_id'] ?? '' ) ),
							'action_index'         => $action_index,
							'execution_profile'    => sanitize_text_field( (string) ( $action['execution_profile'] ?? '' ) ),
							'idempotency_key'      => sanitize_text_field( (string) ( $action['idempotency_key'] ?? '' ) ),
							'selected_count'       => $execution_summary['selected_count'],
							'submitted_count'      => $execution_summary['submitted_count'],
							'executed_count'       => $execution_summary['executed_count'],
							'failed_count'         => $execution_summary['failed_count'],
							'blocked_count'        => $execution_summary['blocked_count'],
							'partial_success'      => $execution_summary['partial_success'],
							'retryable'            => $execution_summary['retryable'],
							'operator_next_action' => $execution_summary['operator_next_action'],
							'executed_results'     => $results,
							'execution_record'     => $execution_record,
						)
					)
				);
				return $valid_input;
			}

			$media_alt_live_preflight = $this->media_alt_live_preflight( $proposal_id, $action, $preflight, $approval_context, $correlation_id, $base_request_context );
			if ( is_wp_error( $media_alt_live_preflight ) ) {
				$execution_record = $this->store_failed_execution_record(
					$proposal_id,
					$proposal,
					$actions,
					$results,
					$preflight,
					$correlation_id,
					sanitize_text_field( (string) ( $base_request_context['adapter_request_id'] ?? '' ) ),
					$media_alt_live_preflight,
					$action
				);
				$media_alt_live_preflight->add_data(
					array_merge(
						(array) $media_alt_live_preflight->get_error_data(),
						array( 'execution_record' => $execution_record )
					)
				);
				return $media_alt_live_preflight;
			}
			if ( ! empty( $media_alt_live_preflight ) ) {
				$action['media_alt_live_preflight'] = $media_alt_live_preflight;
			}

			$result = $this->execution_action_runner->execute( $proposal_id, $action, $approval_context, $correlation_id, $base_request_context );
			if ( is_wp_error( $result ) ) {
				$execution_summary = $this->selected_batch_execution_summary( $actions, $results, $action );
				$error_data        = $result->get_error_data();
				$error_data        = is_array( $error_data ) ? $error_data : array();
				$status            = absint( $error_data['status'] ?? 0 );
				if ( 0 === $status ) {
					$status = 409;
				}

				$execution_record = $this->store_failed_execution_record(
					$proposal_id,
					$proposal,
					$actions,
					$results,
					$preflight,
					$correlation_id,
					sanitize_text_field( (string) ( $base_request_context['adapter_request_id'] ?? '' ) ),
					$result,
					$action
				);
				$result->add_data(
					array_merge(
						$error_data,
						array(
							'status'               => $status,
							'proposal_id'          => $proposal_id,
							'correlation_id'       => $correlation_id,
							'action_id'            => sanitize_key( (string) ( $action['action_id'] ?? '' ) ),
							'action_index'         => absint( $action['action_index'] ?? 0 ),
							'execution_profile'    => sanitize_text_field( (string) ( $action['execution_profile'] ?? '' ) ),
							'idempotency_key'      => sanitize_text_field( (string) ( $action['idempotency_key'] ?? '' ) ),
							'selected_count'       => $execution_summary['selected_count'],
							'submitted_count'      => $execution_summary['submitted_count'],
							'executed_count'       => $execution_summary['executed_count'],
							'failed_count'         => $execution_summary['failed_count'],
							'blocked_count'        => $execution_summary['blocked_count'],
							'partial_success'      => $execution_summary['partial_success'],
							'retryable'            => $execution_summary['retryable'],
							'operator_next_action' => $execution_summary['operator_next_action'],
							'executed_results'     => $results,
							'execution_record'     => $execution_record,
						)
					)
				);
				return $result;
			}

			$results[] = $result;
			$outputs[ sanitize_key( (string) ( $result['action_id'] ?? '' ) ) ] = $this->output_map_from_action_result( $result );
		}

		$first_result        = is_array( $results[0] ?? null ) ? $results[0] : array();
		$post_ids            = array_values(
			array_map(
				'absint',
				array_column( $results, 'post_id' )
			)
		);
		$target_ability_ids  = array_values(
			array_unique(
				array_map(
					static function ( $result ) {
						return is_array( $result ) ? sanitize_text_field( (string) ( $result['target_ability_id'] ?? '' ) ) : '';
					},
					$results
				)
			)
		);
		$target_ability_ids  = array_values( array_filter( $target_ability_ids ) );
		$execution_mode      = count( $actions ) > 1 || 'batch_write_actions' === (string) ( $actions[0]['execution_mode'] ?? '' ) ? 'batch_write_actions' : 'single_post';
		$response_ability_id = 1 === count( $target_ability_ids ) ? $target_ability_ids[0] : $proposal_ability_id;
		$execution_summary   = $this->selected_batch_execution_summary( $actions, $results );

		$execution                     = array(
			'ability_id'                      => $response_ability_id,
			'post_id'                         => absint( $first_result['post_id'] ?? 0 ),
			'post_ids'                        => $post_ids,
			'correlation_id'                  => $correlation_id,
			'adapter_request_id'              => (string) ( $base_request_context['adapter_request_id'] ?? '' ),
			'approval_context'                => $approval_context,
			'preflight_source'                => $preflight_source,
			'preflight'                       => $preflight,
			'core_preflight_evidence'         => array(
				'authorized'                           => true,
				'policy_version'                       => sanitize_text_field( (string) ( $approval_context['policy_version'] ?? ( $preflight['policy_version'] ?? '' ) ) ),
				'approved_input_hash'                  => sanitize_text_field( (string) ( $approval_context['approved_input_hash'] ?? ( $preflight['approved_input_hash'] ?? '' ) ) ),
				'correlation_id'                       => $correlation_id,
				'preflight_source'                     => $preflight_source,
				'commit_execution'                     => false,
				'adapter_preflight_source'             => sanitize_text_field( (string) ( $preflight['adapter_preflight_source'] ?? $preflight_source ) ),
				'implementation_posture_status'        => sanitize_key( (string) ( $implementation_posture_evidence['status'] ?? '' ) ),
				'implementation_posture_checked_count' => absint( $implementation_posture_evidence['checked_count'] ?? 0 ),
			),
			'implementation_posture_evidence' => $implementation_posture_evidence,
			'media_alt_live_preflight'        => is_array( $first_result['media_alt_live_preflight'] ?? null ) ? $first_result['media_alt_live_preflight'] : array(),
			'batch_review_feedback'           => $this->batch_review_feedback_from_preflight( $preflight, $proposal ),
			'execution_mode'                  => $execution_mode,
			'selected_count'                  => $execution_summary['selected_count'],
			'submitted_count'                 => $execution_summary['submitted_count'],
			'executed_count'                  => $execution_summary['executed_count'],
			'failed_count'                    => $execution_summary['failed_count'],
			'blocked_count'                   => $execution_summary['blocked_count'],
			'partial_success'                 => $execution_summary['partial_success'],
			'retryable'                       => $execution_summary['retryable'],
			'operator_next_action'            => $execution_summary['operator_next_action'],
			'results'                         => $results,
			'post_status_before'              => (string) ( $first_result['post_status_before'] ?? '' ),
			'post_status_after'               => (string) ( $first_result['post_status_after'] ?? '' ),
			'result'                          => 1 === count( $results ) ? ( $first_result['result'] ?? array() ) : array(
				'success'              => true,
				'execution_mode'       => $execution_mode,
				'selected_count'       => $execution_summary['selected_count'],
				'submitted_count'      => $execution_summary['submitted_count'],
				'executed_count'       => $execution_summary['executed_count'],
				'failed_count'         => $execution_summary['failed_count'],
				'blocked_count'        => $execution_summary['blocked_count'],
				'partial_success'      => $execution_summary['partial_success'],
				'retryable'            => $execution_summary['retryable'],
				'operator_next_action' => $execution_summary['operator_next_action'],
				'results'              => $results,
			),
		);
		$execution['execution_record'] = $this->store_completed_execution_record( $proposal_id, $proposal, $execution );

		return $execution;
	}

	/**
	 * Adds operator-facing feedback to an error without changing its code.
	 *
	 * @param WP_Error            $error Error.
	 * @param array<string,mixed> $feedback Feedback payload.
	 * @return WP_Error
	 */
	private function error_with_operator_feedback( WP_Error $error, array $feedback ): WP_Error {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();
		if ( ! isset( $data['operator_feedback'] ) ) {
			$data['operator_feedback'] = $feedback;
		}
		$error->add_data( $data );

		return $error;
	}

	/**
	 * Builds batch review feedback for a Core from-plan response.
	 *
	 * @param array<string,mixed> $data Core response.
	 * @return array<string,mixed>
	 */
	private function batch_review_feedback_from_proposals( array $data ): array {
		$proposals = is_array( $data['proposals'] ?? null ) ? array_values( $data['proposals'] ) : array();
		$items     = array();

		foreach ( $proposals as $proposal ) {
			if ( ! is_array( $proposal ) ) {
				continue;
			}
			$feedback = $this->batch_review_feedback_from_summary( $this->proposal_batch_review_summary( $proposal ), $proposal );
			if ( ! empty( $feedback ) ) {
				$items[] = $feedback;
			}
		}

		if ( empty( $items ) ) {
			return array();
		}

		$blocked_count        = 0;
		$needs_input_count    = 0;
		$retryable            = false;
		$operator_next_action = 'review_and_approve_or_reject';
		foreach ( $items as $item ) {
			$blocked_count     += absint( $item['blocked_count'] ?? 0 );
			$needs_input_count += absint( $item['needs_input_count'] ?? 0 );
			$retryable          = $retryable || true === (bool) ( $item['retryable'] ?? false );
			if ( 'resolve_blocked_items_before_commit_preflight' === (string) ( $item['operator_next_action'] ?? '' ) ) {
				$operator_next_action = 'resolve_blocked_items_before_commit_preflight';
			}
		}

		return array(
			'schema_version'       => 'npcink_openclaw_adapter_batch_review_feedback.v1',
			'item_count'           => count( $items ),
			'blocked_count'        => $blocked_count,
			'needs_input_count'    => $needs_input_count,
			'retryable'            => $retryable,
			'operator_next_action' => $operator_next_action,
			'core_execution'       => false,
			'commit_execution'     => false,
			'items'                => $items,
		);
	}

	/**
	 * Builds batch review feedback from Core commit-preflight data.
	 *
	 * @param array<string,mixed> $preflight Core preflight response.
	 * @param array<string,mixed> $proposal Core proposal.
	 * @return array<string,mixed>
	 */
	private function batch_review_feedback_from_preflight( array $preflight, array $proposal = array() ): array {
		$item_preflight = is_array( $preflight['proposal_item_preflight'] ?? null ) ? $preflight['proposal_item_preflight'] : array();
		$summary        = is_array( $item_preflight['batch_review_summary'] ?? null ) ? $item_preflight['batch_review_summary'] : array();
		if ( empty( $summary ) ) {
			$summary = $this->proposal_batch_review_summary( $proposal );
		}

		return $this->batch_review_feedback_from_summary( $summary, $proposal );
	}

	/**
	 * Returns the Core batch review summary from a proposal preview.
	 *
	 * @param array<string,mixed> $proposal Core proposal.
	 * @return array<string,mixed>
	 */
	private function proposal_batch_review_summary( array $proposal ): array {
		$preview = is_array( $proposal['preview'] ?? null ) ? $proposal['preview'] : array();
		return is_array( $preview['batch_review_summary'] ?? null ) ? $preview['batch_review_summary'] : array();
	}

	/**
	 * Normalizes Core batch review summary into Adapter-facing feedback.
	 *
	 * @param array<string,mixed> $summary Core summary.
	 * @param array<string,mixed> $proposal Core proposal.
	 * @return array<string,mixed>
	 */
	private function batch_review_feedback_from_summary( array $summary, array $proposal = array() ): array {
		if ( empty( $summary ) ) {
			return array();
		}

		$target_ability_ids = array_values(
			array_filter(
				array_map(
					static function ( $ability_id ): string {
						return sanitize_text_field( (string) $ability_id );
					},
					(array) ( $summary['target_ability_ids'] ?? array() )
				)
			)
		);

		return array(
			'schema_version'        => 'npcink_openclaw_adapter_batch_review_feedback.v1',
			'core_summary_version'  => sanitize_key( (string) ( $summary['summary_version'] ?? 'core-batch-review-summary-v1' ) ),
			'proposal_id'           => sanitize_text_field( (string) ( $proposal['proposal_id'] ?? '' ) ),
			'ability_id'            => sanitize_text_field( (string) ( $proposal['ability_id'] ?? '' ) ),
			'action_count'          => absint( $summary['action_count'] ?? 0 ),
			'executable_count'      => absint( $summary['executable_count'] ?? 0 ),
			'blocked_count'         => absint( $summary['blocked_count'] ?? 0 ),
			'needs_input_count'     => absint( $summary['needs_input_count'] ?? 0 ),
			'warning_count'         => absint( $summary['warning_count'] ?? 0 ),
			'target_ability_ids'    => $target_ability_ids,
			'proposal_ready'        => true === (bool) ( $summary['proposal_ready'] ?? false ),
			'retryable'             => true === (bool) ( $summary['retryable'] ?? false ),
			'operator_next_action'  => sanitize_key( (string) ( $summary['operator_next_action'] ?? '' ) ),
			'final_execution_owner' => sanitize_key( (string) ( $summary['final_execution_owner'] ?? 'adapter_after_core_preflight' ) ),
			'core_execution'        => false,
			'commit_execution'      => false,
			'blocked_items'         => is_array( $summary['blocked_items'] ?? null ) ? array_values( $summary['blocked_items'] ) : array(),
		);
	}

	/**
	 * Builds operator feedback for plan handoff failures.
	 *
	 * @param WP_Error $error Error.
	 * @param string   $plan_ability_id Planning ability id.
	 * @return array<string,mixed>
	 */
	private function plan_handoff_operator_feedback( WP_Error $error, string $plan_ability_id ): array {
		$error_data = $this->error_data_array( $error );
		$core_data  = $this->upstream_error_detail( $error );
		$blocked    = is_array( $error_data['blocked_items'] ?? null ) ? $error_data['blocked_items'] : array();
		if ( empty( $blocked ) && is_array( $core_data['blocked_items'] ?? null ) ) {
			$blocked = $core_data['blocked_items'];
		}

		$reasons = $this->operator_reasons_from_blocked_items( $blocked );
		if ( empty( $reasons ) ) {
			$reasons[] = $error->get_error_message();
		}

		return array(
			'status'                   => 'plan_revision_required',
			'severity'                 => 'error',
			'message'                  => __( 'The plan was not accepted for Core proposal intake.', 'npcink-ai-client-adapter' ),
			'reasons'                  => $reasons,
			'revision_fields'          => $this->operator_revision_fields( $blocked, $core_data ),
			'next_steps'               => array(
				__( 'Show these reasons to the operator.', 'npcink-ai-client-adapter' ),
				__( 'Revise the Toolbox plan or reviewed draft, then submit a new from-plan request.', 'npcink-ai-client-adapter' ),
				__( 'Do not call approve-and-execute until Core creates a proposal.', 'npcink-ai-client-adapter' ),
			),
			'can_retry_after_revision' => true,
			'core_evidence'            => array(
				'plan_ability_id'    => sanitize_text_field( $plan_ability_id ),
				'adapter_error_code' => $error->get_error_code(),
				'core_error_code'    => (string) ( $core_data['code'] ?? '' ),
				'blocked_count'      => count( $blocked ),
			),
		);
	}

	/**
	 * Builds operator feedback for proposal status failures.
	 *
	 * @param array<string,mixed> $proposal Core proposal.
	 * @param string              $status_before Proposal status before execution.
	 * @return array<string,mixed>
	 */
	private function proposal_status_operator_feedback( array $proposal, string $status_before ): array {
		$proposal_id = sanitize_text_field( (string) ( $proposal['proposal_id'] ?? '' ) );
		$ability_id  = sanitize_text_field( (string) ( $proposal['ability_id'] ?? '' ) );
		$reasons     = 'rejected' === $status_before ? $this->core_rejection_reasons( $proposal ) : array();
		if ( empty( $reasons ) ) {
			$reasons[] = sprintf(
				/* translators: %s: proposal status. */
				__( 'Core proposal status is %s.', 'npcink-ai-client-adapter' ),
				$status_before
			);
		}

		return array(
			'status'                   => 'proposal_' . sanitize_key( $status_before ),
			'severity'                 => 'error',
			'message'                  => 'rejected' === $status_before
				? __( 'Core rejected this proposal. Adapter will not execute it.', 'npcink-ai-client-adapter' )
				: __( 'This proposal is not in an executable Core status.', 'npcink-ai-client-adapter' ),
			'reasons'                  => $reasons,
			'revision_fields'          => array(),
			'next_steps'               => array(
				__( 'Show the Core decision to the operator.', 'npcink-ai-client-adapter' ),
				__( 'Revise the source plan or draft, then create a new Core proposal.', 'npcink-ai-client-adapter' ),
				__( 'Do not retry approve-and-execute against this proposal id.', 'npcink-ai-client-adapter' ),
			),
			'can_retry_after_revision' => true,
			'core_evidence'            => array(
				'proposal_id'  => $proposal_id,
				'ability_id'   => $ability_id,
				'status'       => sanitize_key( $status_before ),
				'detail_route' => '/wp-json/npcink-governance-core/v1/proposals/' . rawurlencode( $proposal_id ),
			),
		);
	}

	/**
	 * Builds operator feedback for commit-preflight failures.
	 *
	 * @param WP_Error|null       $error Error, when Core returned one.
	 * @param array<string,mixed> $proposal Core proposal.
	 * @param array<string,mixed> $preflight Preflight payload, when available.
	 * @return array<string,mixed>
	 */
	private function preflight_operator_feedback( ?WP_Error $error, array $proposal, array $preflight = array() ): array {
		if ( empty( $preflight ) && null !== $error ) {
			$preflight = $this->upstream_error_detail( $error );
		}

		$item_preflight        = is_array( $preflight['proposal_item_preflight'] ?? null ) ? $preflight['proposal_item_preflight'] : array();
		$blocked               = is_array( $item_preflight['blocked_items'] ?? null ) ? $item_preflight['blocked_items'] : array();
		$needs_input           = array_values( array_map( 'sanitize_key', (array) ( $item_preflight['needs_input'] ?? array() ) ) );
		$batch_review_feedback = $this->batch_review_feedback_from_preflight( $preflight, $proposal );
		$reasons               = $this->operator_reasons_from_blocked_items( $blocked );

		foreach ( $needs_input as $field ) {
			$reasons[] = sprintf(
				/* translators: %s: missing field name. */
				__( 'Missing required input: %s.', 'npcink-ai-client-adapter' ),
				$field
			);
		}

		if ( false === (bool) ( $item_preflight['proposal_ready'] ?? true ) && empty( $reasons ) ) {
			$reasons[] = __( 'Core marks the proposal item as not ready for execution.', 'npcink-ai-client-adapter' );
		}
		if ( empty( $reasons ) ) {
			$reasons[] = null !== $error ? $error->get_error_message() : __( 'Core commit preflight did not authorize execution.', 'npcink-ai-client-adapter' );
		}

		$core_error_code = null !== $error ? $error->get_error_code() : '';
		if ( 'npcink_governance_core_commit_preflight_already_issued' === $core_error_code ) {
			$reasons[] = __( 'Core has already issued the one-time execution handoff. If commit-preflight was called directly against Core, Adapter cannot recover that handoff.', 'npcink-ai-client-adapter' );
		}

		$next_steps = array(
			__( 'Show Core preflight blockers to the operator.', 'npcink-ai-client-adapter' ),
			__( 'Revise the proposal input or source plan, then create a new proposal.', 'npcink-ai-client-adapter' ),
			__( 'Do not retry approve-and-execute until Core preflight can pass.', 'npcink-ai-client-adapter' ),
		);
		if ( 'npcink_governance_core_commit_preflight_already_issued' === $core_error_code ) {
			$next_steps = array(
				__( 'Create a new proposal for the same intended write.', 'npcink-ai-client-adapter' ),
				__( 'After approval, call Adapter execute or approve-and-execute; do not call Core commit-preflight directly.', 'npcink-ai-client-adapter' ),
				__( 'Use Adapter commit-preflight only as an advanced diagnostic step and follow it immediately with Adapter execute.', 'npcink-ai-client-adapter' ),
			);
		}

		return array(
			'status'                   => 'preflight_blocked',
			'severity'                 => 'error',
			'message'                  => __( 'Core commit preflight blocked execution. Adapter did not run the write ability.', 'npcink-ai-client-adapter' ),
			'reasons'                  => array_values( array_unique( $reasons ) ),
			'revision_fields'          => array_values( array_unique( $needs_input ) ),
			'next_steps'               => $next_steps,
			'can_retry_after_revision' => true,
			'core_evidence'            => array(
				'proposal_id'             => sanitize_text_field( (string) ( $proposal['proposal_id'] ?? '' ) ),
				'ability_id'              => sanitize_text_field( (string) ( $proposal['ability_id'] ?? '' ) ),
				'status'                  => sanitize_key( (string) ( $proposal['status'] ?? '' ) ),
				'core_error_code'         => $core_error_code,
				'proposal_item_preflight' => $item_preflight,
				'batch_review_feedback'   => $batch_review_feedback,
				'commit_execution'        => false,
			),
		);
	}

	/**
	 * Returns rejection reasons from Core audit timeline.
	 *
	 * @param array<string,mixed> $proposal Core proposal.
	 * @return array<int,string>
	 */
	private function core_rejection_reasons( array $proposal ): array {
		$reasons = array();
		foreach ( (array) ( $proposal['audit_timeline'] ?? array() ) as $event ) {
			if ( ! is_array( $event ) || 'proposal.rejected' !== (string) ( $event['event_name'] ?? '' ) ) {
				continue;
			}

			$metadata = is_array( $event['metadata'] ?? null ) ? $event['metadata'] : array();
			$note     = sanitize_textarea_field( (string) ( $metadata['note'] ?? '' ) );
			if ( '' !== $note ) {
				$reasons[] = $note;
			}
		}

		return array_values( array_unique( $reasons ) );
	}

	/**
	 * Extracts readable reasons from blocked item rows.
	 *
	 * @param array<int,mixed> $blocked_items Blocked items.
	 * @return array<int,string>
	 */
	private function operator_reasons_from_blocked_items( array $blocked_items ): array {
		$reasons = array();
		foreach ( $blocked_items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$reason = sanitize_textarea_field( (string) ( $item['reason'] ?? '' ) );
			$code   = sanitize_key( (string) ( $item['block_code'] ?? ( $item['code'] ?? '' ) ) );
			if ( '' !== $reason && '' !== $code ) {
				$reasons[] = $code . ': ' . $reason;
			} elseif ( '' !== $reason ) {
				$reasons[] = $reason;
			} elseif ( '' !== $code ) {
				$reasons[] = $code;
			}
		}

		return array_values( array_unique( $reasons ) );
	}

	/**
	 * Extracts likely fields the operator needs to revise.
	 *
	 * @param array<int,mixed>    $blocked_items Blocked items.
	 * @param array<string,mixed> $core_data Core or Adapter error data.
	 * @return array<int,string>
	 */
	private function operator_revision_fields( array $blocked_items, array $core_data ): array {
		$fields = array();
		foreach ( $blocked_items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( isset( $item['field'] ) ) {
				$fields[] = sanitize_key( (string) $item['field'] );
			}
			foreach ( (array) ( $item['fields'] ?? array() ) as $field ) {
				$fields[] = sanitize_key( (string) $field );
			}
		}

		foreach ( (array) ( $core_data['needs_input'] ?? array() ) as $field ) {
			$fields[] = sanitize_key( (string) $field );
		}

		return array_values( array_unique( array_filter( $fields ) ) );
	}

	/**
	 * Returns a WP_Error data array.
	 *
	 * @param WP_Error $error Error.
	 * @return array<string,mixed>
	 */
	private function error_data_array( WP_Error $error ): array {
		$data = $error->get_error_data();
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Extracts the upstream error detail payload from Adapter wrapped errors.
	 *
	 * @param WP_Error $error Error.
	 * @return array<string,mixed>
	 */
	private function upstream_error_detail( WP_Error $error ): array {
		$data     = $this->error_data_array( $error );
		$upstream = is_array( $data['upstream_data'] ?? null ) ? $data['upstream_data'] : array();
		if ( empty( $upstream ) ) {
			return $data;
		}

		$detail = is_array( $upstream['data'] ?? null ) ? $upstream['data'] : $upstream;
		if ( is_array( $detail['data'] ?? null ) ) {
			$detail = $detail['data'];
		}

		if ( ! isset( $detail['code'] ) && isset( $upstream['code'] ) ) {
			$detail['code'] = $upstream['code'];
		}
		if ( ! isset( $detail['message'] ) && isset( $upstream['message'] ) ) {
			$detail['message'] = $upstream['message'];
		}

		return is_array( $detail ) ? $detail : array();
	}

	/**
	 * Returns stored preflight handoffs issued through Adapter.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function preflight_handoffs(): array {
		$records = get_option( self::PREFLIGHT_HANDOFFS_OPTION, array() );
		return is_array( $records ) ? $records : array();
	}

	/**
	 * Stores a Core preflight handoff for the next Adapter execute call.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param array<string,mixed> $proposal Core proposal.
	 * @param array<string,mixed> $preflight Core preflight payload.
	 * @return array<string,mixed>|null
	 */
	private function store_preflight_handoff( string $proposal_id, array $proposal, array $preflight ): ?array {
		if ( '' === $proposal_id || empty( $proposal ) ) {
			return null;
		}

		$approval_context = is_array( $preflight['approval_context'] ?? null ) ? $preflight['approval_context'] : array();
		$approved_hash    = sanitize_text_field( (string) ( $approval_context['approved_input_hash'] ?? ( $preflight['approved_input_hash'] ?? '' ) ) );
		$current_hash     = $this->proposal_input_hash( $proposal );
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

		$binding = $this->validate_preflight_binding( $proposal_id, $proposal, $preflight );
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

		$records = $this->preflight_handoffs();
		$records[ $this->execution_record_key( $proposal_id ) ] = $handoff;
		$records = $this->prune_preflight_handoffs( $records );
		update_option( self::PREFLIGHT_HANDOFFS_OPTION, $records, false );

		return $handoff;
	}

	/**
	 * Consumes a cached preflight handoff when it still matches the proposal.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param array<string,mixed> $proposal Core proposal.
	 * @return array<string,mixed>|null
	 */
	private function consume_cached_preflight_handoff( string $proposal_id, array $proposal ): ?array {
		$records = $this->preflight_handoffs();
		$key     = $this->execution_record_key( $proposal_id );
		$record  = is_array( $records[ $key ] ?? null ) ? $records[ $key ] : array();
		if ( empty( $record ) ) {
			return null;
		}

		unset( $records[ $key ] );
		update_option( self::PREFLIGHT_HANDOFFS_OPTION, $records, false );

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
			|| $approved_hash !== $this->proposal_input_hash( $proposal )
			|| 'core-preflight-v1' !== $policy_version
		) {
			return null;
		}

		$binding = $this->validate_preflight_binding( $proposal_id, $proposal, $preflight );
		if ( is_wp_error( $binding ) ) {
			return null;
		}

		return $preflight;
	}

	/**
	 * Removes old preflight handoffs after the bounded retention limit.
	 *
	 * @param array<string,array<string,mixed>> $records Records.
	 * @return array<string,array<string,mixed>>
	 */
	private function prune_preflight_handoffs( array $records ): array {
		$oldest = time() - self::PREFLIGHT_HANDOFF_RETENTION_TTL;
		foreach ( $records as $key => $record ) {
			$issued_at = is_array( $record ) ? strtotime( (string) ( $record['issued_at'] ?? '' ) ) : false;
			if ( false === $issued_at || $issued_at < $oldest ) {
				unset( $records[ $key ] );
			}
		}

		if ( count( $records ) <= self::MAX_PREFLIGHT_HANDOFFS ) {
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

		return array_slice( $records, - self::MAX_PREFLIGHT_HANDOFFS, null, true );
	}

	/**
	 * Verifies Core preflight still binds to the approved proposal input and policy.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param array<string,mixed> $proposal Core proposal.
	 * @param array<string,mixed> $preflight Core preflight payload.
	 * @return true|WP_Error
	 */
	private function validate_preflight_binding( string $proposal_id, array $proposal, array $preflight ) {
		$approval_context  = is_array( $preflight['approval_context'] ?? null ) ? $preflight['approval_context'] : array();
		$execution_handoff = is_array( $preflight['execution_handoff'] ?? null ) ? $preflight['execution_handoff'] : array();
		$current_hash      = $this->proposal_input_hash( $proposal );
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

		$handoff_binding = $this->validate_execution_handoff_binding( $proposal_id, $proposal, $preflight, $approval_context, $execution_handoff );
		if ( is_wp_error( $handoff_binding ) ) {
			return $handoff_binding;
		}

		$approval_site_binding = $this->validate_core_context_site_binding( $approval_context, 'npcink_openclaw_adapter_preflight', 409 );
		if ( is_wp_error( $approval_site_binding ) ) {
			return $approval_site_binding;
		}
		$handoff_site_binding = $this->validate_core_context_site_binding( $execution_handoff, 'npcink_openclaw_adapter_preflight_handoff', 409 );
		if ( is_wp_error( $handoff_site_binding ) ) {
			return $handoff_site_binding;
		}
		$approval_expiry = $this->validate_core_context_expiry( $approval_context, 'npcink_openclaw_adapter_preflight', 409 );
		if ( is_wp_error( $approval_expiry ) ) {
			return $approval_expiry;
		}
		$handoff_expiry = $this->validate_core_context_expiry( $execution_handoff, 'npcink_openclaw_adapter_preflight_handoff', 409 );
		if ( is_wp_error( $handoff_expiry ) ) {
			return $handoff_expiry;
		}
		$approval_client_binding = $this->validate_core_context_signed_client_binding( $approval_context, 'npcink_openclaw_adapter_preflight', 409 );
		if ( is_wp_error( $approval_client_binding ) ) {
			return $approval_client_binding;
		}
		$handoff_client_binding = $this->validate_core_context_signed_client_binding( $execution_handoff, 'npcink_openclaw_adapter_preflight_handoff', 409 );
		if ( is_wp_error( $handoff_client_binding ) ) {
			return $handoff_client_binding;
		}

		return true;
	}

	/**
	 * Builds execution-time evidence for provider-declared implementation posture.
	 *
	 * @param string                       $proposal_id Proposal id.
	 * @param array<int,array<string,mixed>> $actions Normalized execution actions.
	 * @param array<string,mixed>          $preflight Core preflight payload.
	 * @return array<string,mixed>|WP_Error
	 */
	private function implementation_posture_execution_evidence( string $proposal_id, array $actions, array $preflight ) {
		$ability_ids = array();
		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$ability_id = sanitize_text_field( (string) ( $action['ability_id'] ?? ( $action['target_ability_id'] ?? '' ) ) );
			if ( '' !== $ability_id ) {
				$ability_ids[] = $ability_id;
			}
		}
		$ability_ids = array_values( array_unique( $ability_ids ) );

		$items              = array();
		$checked_count      = 0;
		$not_declared_count = 0;
		foreach ( $ability_ids as $ability_id ) {
			$item = $this->implementation_posture_evidence_item( $proposal_id, $ability_id, $preflight );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			if ( 'checked' === (string) ( $item['status'] ?? '' ) ) {
				++$checked_count;
			}
			if ( 'not_declared' === (string) ( $item['status'] ?? '' ) ) {
				++$not_declared_count;
			}
			$items[] = $item;
		}

		return array(
			'schema_version'                     => 'npcink_openclaw_adapter_implementation_posture_evidence.v1',
			'status'                             => $checked_count > 0 ? 'checked' : 'not_declared',
			'checked_count'                      => $checked_count,
			'not_declared_count'                 => $not_declared_count,
			'ability_count'                      => count( $ability_ids ),
			'capabilities_surface'               => '/wp-json/npcink-governance-core/v1/capabilities',
			'core_preflight_contract_validation' => true,
			'metadata_only'                      => true,
			'items'                              => $items,
		);
	}

	/**
	 * Builds posture evidence for one target ability.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param string              $ability_id Ability id.
	 * @param array<string,mixed> $preflight Core preflight payload.
	 * @return array<string,mixed>|WP_Error
	 */
	private function implementation_posture_evidence_item( string $proposal_id, string $ability_id, array $preflight ) {
		$capability = $this->find_core_capability( $ability_id );
		if ( is_wp_error( $capability ) ) {
			return $capability;
		}

		$posture = is_array( $capability['implementation_posture'] ?? null ) ? $capability['implementation_posture'] : array();
		if ( empty( $posture ) || true !== (bool) ( $capability['implementation_posture_available'] ?? false ) ) {
			return array(
				'ability_id' => $ability_id,
				'status'     => 'not_declared',
			);
		}

		$valid = $this->validate_implementation_posture_for_execution( $proposal_id, $ability_id, $posture );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$preflight_capability = is_array( $preflight['capability'] ?? null ) ? $preflight['capability'] : array();
		if ( (string) ( $preflight_capability['ability_id'] ?? '' ) === $ability_id ) {
			$preflight_posture = is_array( $preflight_capability['implementation_posture'] ?? null ) ? $preflight_capability['implementation_posture'] : array();
			// phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- loose array comparison tolerates numeric-string/int drift between Core payload views.
			if ( ! empty( $preflight_posture ) && $posture != $preflight_posture ) {
				return $this->implementation_posture_mismatch_error( $proposal_id, $ability_id, 'capability' );
			}
		}

		$contract_preflight = is_array( $preflight['contract_preflight'] ?? null ) ? $preflight['contract_preflight'] : array();
		$current_contract   = is_array( $contract_preflight['current_contract'] ?? null ) ? $contract_preflight['current_contract'] : array();
		if ( (string) ( $current_contract['ability_id'] ?? '' ) === $ability_id ) {
			$contract_posture = is_array( $current_contract['implementation_posture'] ?? null ) ? $current_contract['implementation_posture'] : array();
			// phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- loose array comparison tolerates numeric-string/int drift between Core payload views.
			if ( ! empty( $contract_posture ) && $posture != $contract_posture ) {
				return $this->implementation_posture_mismatch_error( $proposal_id, $ability_id, 'contract_preflight' );
			}
		}

		return array(
			'ability_id'                     => $ability_id,
			'status'                         => 'checked',
			'schema_version'                 => sanitize_text_field( (string) ( $posture['schema_version'] ?? '' ) ),
			'write_posture'                  => sanitize_key( (string) ( $posture['write_posture'] ?? '' ) ),
			'commit_authority'               => sanitize_key( (string) ( $posture['commit_authority'] ?? '' ) ),
			'final_authorization_owner'      => sanitize_key( (string) ( $posture['final_authorization_owner'] ?? '' ) ),
			'approval_truth_owner'           => sanitize_key( (string) ( $posture['approval_truth_owner'] ?? '' ) ),
			'audit_truth_owner'              => sanitize_key( (string) ( $posture['audit_truth_owner'] ?? '' ) ),
			'dry_run_default'                => true === (bool) ( $posture['dry_run_default'] ?? false ),
			'commit_default'                 => true === (bool) ( $posture['commit_default'] ?? false ),
			'direct_wordpress_write_default' => true === (bool) ( $posture['direct_wordpress_write_default'] ?? false ),
			'forbidden_ownership_flags'      => $this->implementation_posture_enabled_forbidden_flags( $posture ),
		);
	}

	/**
	 * Validates provider posture before Adapter final execution.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param string              $ability_id Ability id.
	 * @param array<string,mixed> $posture Provider posture.
	 * @return true|WP_Error
	 */
	private function validate_implementation_posture_for_execution( string $proposal_id, string $ability_id, array $posture ) {
		$expected = array(
			'schema_version'            => 'npcink_abilities_toolkit_implementation_posture.v1',
			'write_posture'             => 'host_governed_dry_run_first',
			'commit_authority'          => 'host_runtime_approval_context_required',
			'final_authorization_owner' => 'host_governance_layer',
			'approval_truth_owner'      => 'host_governance_layer',
			'audit_truth_owner'         => 'host_governance_layer',
		);

		foreach ( $expected as $field => $value ) {
			$actual = 'schema_version' === $field
				? sanitize_text_field( (string) ( $posture[ $field ] ?? '' ) )
				: sanitize_key( (string) ( $posture[ $field ] ?? '' ) );
			if ( $value === $actual ) {
				continue;
			}

			return new WP_Error(
				'npcink_openclaw_adapter_implementation_posture_invalid',
				__( 'Provider implementation posture is not accepted for Adapter final execution.', 'npcink-ai-client-adapter' ),
				array(
					'status'           => 409,
					'proposal_id'      => $proposal_id,
					'ability_id'       => $ability_id,
					'field'            => $field,
					'expected_value'   => $value,
					'actual_value'     => $actual,
					'commit_execution' => false,
				)
			);
		}

		foreach (
			array(
				'dry_run_default'                => true,
				'commit_default'                 => false,
				'direct_wordpress_write_default' => false,
			) as $field => $expected_bool
		) {
			if ( array_key_exists( $field, $posture ) && $expected_bool === (bool) $posture[ $field ] ) {
				continue;
			}

			return new WP_Error(
				'npcink_openclaw_adapter_implementation_posture_invalid',
				__( 'Provider implementation posture write defaults are not accepted for Adapter final execution.', 'npcink-ai-client-adapter' ),
				array(
					'status'           => 409,
					'proposal_id'      => $proposal_id,
					'ability_id'       => $ability_id,
					'field'            => $field,
					'expected_value'   => $expected_bool,
					'actual_value'     => (bool) ( $posture[ $field ] ?? null ),
					'commit_execution' => false,
				)
			);
		}

		$forbidden_flags = $this->implementation_posture_enabled_forbidden_flags( $posture );
		if ( ! empty( $forbidden_flags ) ) {
			return new WP_Error(
				'npcink_openclaw_adapter_implementation_posture_forbidden_ownership',
				__( 'Provider implementation posture declares ownership that Adapter must not execute through.', 'npcink-ai-client-adapter' ),
				array(
					'status'                    => 409,
					'proposal_id'               => $proposal_id,
					'ability_id'                => $ability_id,
					'forbidden_ownership_flags' => $forbidden_flags,
					'commit_execution'          => false,
				)
			);
		}

		return true;
	}

	/**
	 * Returns forbidden ownership flags enabled by provider posture.
	 *
	 * @param array<string,mixed> $posture Provider posture.
	 * @return array<int,string>
	 */
	private function implementation_posture_enabled_forbidden_flags( array $posture ): array {
		$enabled = array();
		foreach (
			array(
				'workflow_runtime',
				'queue_or_scheduler',
				'model_' . 'routing',
				'provider_' . 'credentials',
				'approval_storage',
				'audit_storage',
			) as $field
		) {
			if ( true === (bool) ( $posture[ $field ] ?? false ) ) {
				$enabled[] = $field;
			}
		}

		return $enabled;
	}

	/**
	 * Returns an implementation posture mismatch error.
	 *
	 * @param string $proposal_id Proposal id.
	 * @param string $ability_id Ability id.
	 * @param string $source Mismatch source.
	 * @return WP_Error
	 */
	private function implementation_posture_mismatch_error( string $proposal_id, string $ability_id, string $source ): WP_Error {
		return new WP_Error(
			'npcink_openclaw_adapter_implementation_posture_mismatch',
			__( 'Core implementation posture evidence does not match capability discovery.', 'npcink-ai-client-adapter' ),
			array(
				'status'           => 409,
				'proposal_id'      => $proposal_id,
				'ability_id'       => $ability_id,
				'evidence_source'  => sanitize_key( $source ),
				'commit_execution' => false,
			)
		);
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
	private function validate_execution_handoff_binding( string $proposal_id, array $proposal, array $preflight, array $approval_context, array $execution_handoff ) {
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
		$allowed_ability_ids = $this->proposal_handoff_ability_ids( $proposal );
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
	 * Returns proposal and target write-action ability ids accepted for a Core handoff.
	 *
	 * @param array<string,mixed> $proposal Core proposal.
	 * @return array<int,string>
	 */
	private function proposal_handoff_ability_ids( array $proposal ): array {
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
	private function validate_core_context_site_binding( array $context, string $code_prefix, int $status ) {
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
	private function validate_core_context_expiry( array $context, string $code_prefix, int $status ) {
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
	private function validate_core_context_signed_client_binding( array $context, string $code_prefix, int $status ) {
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

		$current_fingerprint = $this->current_signed_client_fingerprint();
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
	 * Builds the same input hash Core commit-preflight uses for approved inputs.
	 *
	 * @param array<string,mixed> $proposal Core proposal.
	 * @return string
	 */
	private function proposal_input_hash( array $proposal ): string {
		$json = wp_json_encode( $proposal['input'] ?? array() );
		return hash( 'sha256', is_string( $json ) ? $json : '' );
	}


	/**
	 * Returns the adapter-scoped execution record storage key.
	 *
	 * @param string $proposal_id Proposal id.
	 * @return string
	 */
	private function execution_record_key( string $proposal_id ): string {
		return $this->execution_records->record_key( $proposal_id );
	}

	/**
	 * Returns Adapter-owned execution records.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function execution_records(): array {
		return $this->execution_records->records();
	}

	/**
	 * Prunes expired execution records.
	 *
	 * @param array<string,array<string,mixed>> $records Records.
	 * @return array<string,array<string,mixed>>
	 */
	private function prune_execution_records( array $records ): array {
		return $this->execution_records->prune( $records );
	}

	/**
	 * Returns the execution record for a proposal.
	 *
	 * @param string $proposal_id Proposal id.
	 * @return array<string,mixed>|null
	 */
	private function execution_record_for_proposal( string $proposal_id ): ?array {
		return $this->execution_records->record_for_proposal( $proposal_id );
	}

	/**
	 * Returns the completed execution record for a proposal.
	 *
	 * @param string $proposal_id Proposal id.
	 * @return array<string,mixed>|null
	 */
	private function completed_execution_record( string $proposal_id ): ?array {
		return $this->execution_records->completed_record( $proposal_id );
	}

	/**
	 * Builds the already-completed execution error.
	 *
	 * @param string                $proposal_id Proposal id.
	 * @param array<string,mixed>   $record Record.
	 * @return WP_Error
	 */
	private function execution_already_completed_error( string $proposal_id, array $record ): WP_Error {
		return $this->execution_records->already_completed_error( $proposal_id, $record );
	}

	/**
	 * Returns the public projection of an execution record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return array<string,mixed>
	 */
	private function public_execution_record( array $record ): array {
		return $this->execution_records->public_record( $record );
	}

	/**
	 * Returns the compact verification projection of an execution.
	 *
	 * @param array<string,mixed> $execution Execution.
	 * @return array<string,mixed>|null
	 */
	private function compact_execution_verification( array $execution ): ?array {
		return $this->execution_records->compact_verification( $execution );
	}

	/**
	 * Acquires the execution lock for a proposal.
	 *
	 * @param string $proposal_id Proposal id.
	 * @return string|WP_Error Lock key or lock contention error.
	 */
	private function acquire_execution_lock( string $proposal_id ) {
		return $this->execution_records->acquire_lock( $proposal_id );
	}

	/**
	 * Releases an execution lock.
	 *
	 * @param string $lock_key Lock key.
	 * @return void
	 */
	private function release_execution_lock( string $lock_key ): void {
		$this->execution_records->release_lock( $lock_key );
	}

	/**
	 * Stores one successful execution record.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param array<string,mixed> $proposal Core proposal.
	 * @param array<string,mixed> $execution Execution result.
	 * @return array<string,mixed>
	 */
	private function store_completed_execution_record( string $proposal_id, array $proposal, array $execution ): array {
		$approval_context                = is_array( $execution['approval_context'] ?? null ) ? $execution['approval_context'] : array();
		$preflight                       = is_array( $execution['preflight'] ?? null ) ? $execution['preflight'] : array();
		$record                          = array(
			'status'                          => 'succeeded',
			'proposal_id'                     => $proposal_id,
			'ability_id'                      => sanitize_text_field( (string) ( $execution['ability_id'] ?? '' ) ),
			'proposal_ability_id'             => sanitize_text_field( (string) ( $proposal['ability_id'] ?? '' ) ),
			'approved_input_hash'             => sanitize_text_field( (string) ( $approval_context['approved_input_hash'] ?? ( $preflight['approved_input_hash'] ?? '' ) ) ),
			'correlation_id'                  => sanitize_text_field( (string) ( $execution['correlation_id'] ?? '' ) ),
			'adapter_request_id'              => sanitize_text_field( (string) ( $execution['adapter_request_id'] ?? '' ) ),
			'execution_mode'                  => sanitize_key( (string) ( $execution['execution_mode'] ?? '' ) ),
			'execution_surface'               => 'wp_abilities_rest',
			'execution_handoff_posture'       => $this->execution_handoff_posture(),
			'commit_execution'                => false,
			'post_id'                         => absint( $execution['post_id'] ?? 0 ),
			'post_ids'                        => array_values( array_map( 'absint', is_array( $execution['post_ids'] ?? null ) ? $execution['post_ids'] : array() ) ),
			'selected_count'                  => absint( $execution['selected_count'] ?? ( $execution['executed_count'] ?? 0 ) ),
			'submitted_count'                 => absint( $execution['submitted_count'] ?? ( $execution['executed_count'] ?? 0 ) ),
			'executed_count'                  => absint( $execution['executed_count'] ?? 0 ),
			'failed_count'                    => absint( $execution['failed_count'] ?? 0 ),
			'blocked_count'                   => absint( $execution['blocked_count'] ?? 0 ),
			'partial_success'                 => (bool) ( $execution['partial_success'] ?? false ),
			'retryable'                       => (bool) ( $execution['retryable'] ?? false ),
			'operator_next_action'            => sanitize_key( (string) ( $execution['operator_next_action'] ?? '' ) ),
			'core_preflight_evidence'         => is_array( $execution['core_preflight_evidence'] ?? null ) ? $execution['core_preflight_evidence'] : array(),
			'implementation_posture_evidence' => is_array( $execution['implementation_posture_evidence'] ?? null ) ? $execution['implementation_posture_evidence'] : array(),
			'media_alt_live_preflight'        => is_array( $execution['media_alt_live_preflight'] ?? null ) ? $execution['media_alt_live_preflight'] : array(),
			'verification'                    => $this->compact_execution_verification( $execution ),
			'executed_at'                     => gmdate( 'c' ),
		);
		$record['core_execution_record'] = $this->record_core_execution_result( $proposal_id, $record );

		$records = $this->execution_records();
		$records[ $this->execution_record_key( $proposal_id ) ] = $record;
		$records = $this->prune_execution_records( $records );
		update_option( self::EXECUTION_RECORDS_OPTION, $records, false );

		return $this->public_execution_record( $record );
	}

	/**
	 * Stores one failed execution summary after Core preflight was consumed.
	 *
	 * @param string                    $proposal_id Proposal id.
	 * @param array<string,mixed>       $proposal Core proposal.
	 * @param array<int,array<string,mixed>> $actions Normalized actions.
	 * @param array<int,array<string,mixed>> $results Executed action results.
	 * @param array<string,mixed>       $preflight Core preflight payload.
	 * @param string                    $correlation_id Correlation id.
	 * @param string                    $adapter_request_id Adapter request id.
	 * @param WP_Error                  $error Execution error.
	 * @param array<string,mixed>|null  $failed_action Failed action.
	 * @return array<string,mixed>
	 */
	private function store_failed_execution_record( string $proposal_id, array $proposal, array $actions, array $results, array $preflight, string $correlation_id, string $adapter_request_id, WP_Error $error, ?array $failed_action = null ): array {
		$approval_context                = is_array( $preflight['approval_context'] ?? null ) ? $preflight['approval_context'] : array();
		$first_action                    = is_array( $actions[0] ?? null ) ? $actions[0] : array();
		$failed_action                   = is_array( $failed_action ) ? $failed_action : $first_action;
		$execution_summary               = $this->selected_batch_execution_summary( $actions, $results, $failed_action );
		$execution_mode                  = count( $actions ) > 1 || 'batch_write_actions' === (string) ( $first_action['execution_mode'] ?? '' ) ? 'batch_write_actions' : 'single_post';
		$target_ability_id               = sanitize_text_field( (string) ( $failed_action['ability_id'] ?? ( $first_action['ability_id'] ?? ( $proposal['ability_id'] ?? '' ) ) ) );
		$record                          = array(
			'status'                          => 'failed',
			'proposal_id'                     => $proposal_id,
			'ability_id'                      => $target_ability_id,
			'proposal_ability_id'             => sanitize_text_field( (string) ( $proposal['ability_id'] ?? '' ) ),
			'approved_input_hash'             => sanitize_text_field( (string) ( $approval_context['approved_input_hash'] ?? ( $preflight['approved_input_hash'] ?? '' ) ) ),
			'correlation_id'                  => sanitize_text_field( $correlation_id ),
			'adapter_request_id'              => sanitize_text_field( $adapter_request_id ),
			'execution_mode'                  => sanitize_key( $execution_mode ),
			'execution_surface'               => 'wp_abilities_rest',
			'execution_handoff_posture'       => $this->execution_handoff_posture(),
			'commit_execution'                => false,
			'post_id'                         => absint( $failed_action['post_id'] ?? 0 ),
			'post_ids'                        => array_values( array_map( 'absint', array_column( $results, 'post_id' ) ) ),
			'selected_count'                  => $execution_summary['selected_count'],
			'submitted_count'                 => $execution_summary['submitted_count'],
			'executed_count'                  => $execution_summary['executed_count'],
			'failed_count'                    => $execution_summary['failed_count'],
			'blocked_count'                   => $execution_summary['blocked_count'],
			'partial_success'                 => $execution_summary['partial_success'],
			'retryable'                       => $execution_summary['retryable'],
			'operator_next_action'            => $execution_summary['operator_next_action'],
			'error_code'                      => sanitize_key( $error->get_error_code() ),
			'failed_action_id'                => sanitize_key( (string) ( $failed_action['action_id'] ?? '' ) ),
			'failed_action_index'             => absint( $failed_action['action_index'] ?? 0 ),
			'failed_execution_profile'        => sanitize_text_field( (string) ( $failed_action['execution_profile'] ?? '' ) ),
			'failed_idempotency_key'          => sanitize_text_field( (string) ( $failed_action['idempotency_key'] ?? '' ) ),
			'core_preflight_evidence'         => array(
				'authorized'                           => true === (bool) ( $approval_context['approval_commit_authorized'] ?? false ),
				'policy_version'                       => sanitize_text_field( (string) ( $approval_context['policy_version'] ?? ( $preflight['policy_version'] ?? '' ) ) ),
				'approved_input_hash'                  => sanitize_text_field( (string) ( $approval_context['approved_input_hash'] ?? ( $preflight['approved_input_hash'] ?? '' ) ) ),
				'correlation_id'                       => sanitize_text_field( $correlation_id ),
				'preflight_source'                     => sanitize_text_field( (string) ( $preflight['adapter_preflight_source'] ?? '' ) ),
				'commit_execution'                     => false,
				'adapter_preflight_source'             => sanitize_text_field( (string) ( $preflight['adapter_preflight_source'] ?? '' ) ),
				'implementation_posture_status'        => sanitize_key( (string) ( $preflight['implementation_posture_evidence']['status'] ?? '' ) ),
				'implementation_posture_checked_count' => absint( $preflight['implementation_posture_evidence']['checked_count'] ?? 0 ),
			),
			'implementation_posture_evidence' => is_array( $preflight['implementation_posture_evidence'] ?? null ) ? $preflight['implementation_posture_evidence'] : array(),
			'failed_at'                       => gmdate( 'c' ),
			'executed_at'                     => gmdate( 'c' ),
		);
		$record['core_execution_record'] = $this->record_core_execution_result( $proposal_id, $record );

		$records = $this->execution_records();
		$records[ $this->execution_record_key( $proposal_id ) ] = $record;
		$records = $this->prune_execution_records( $records );
		update_option( self::EXECUTION_RECORDS_OPTION, $records, false );

		return $this->public_execution_record( $record );
	}


	/**
	 * Records Adapter execution outcome back to Core lifecycle state.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param array<string,mixed> $record Adapter execution record.
	 * @return array<string,mixed>
	 */
	private function record_core_execution_result( string $proposal_id, array $record ): array {
		$status = sanitize_key( (string) ( $record['status'] ?? '' ) );
		if ( ! in_array( $status, array( 'succeeded', 'failed' ), true ) ) {
			return array(
				'recorded' => false,
				'status'   => 'skipped',
				'reason'   => 'unsupported_adapter_execution_status',
			);
		}

		$response = $this->dispatch_upstream(
			'POST',
			'/npcink-governance-core/v1/proposals/' . rawurlencode( $proposal_id ) . '/record-execution',
			array(
				'execution_status'     => $status,
				'correlation_id'       => sanitize_text_field( (string) ( $record['correlation_id'] ?? '' ) ),
				'approved_input_hash'  => sanitize_text_field( (string) ( $record['approved_input_hash'] ?? '' ) ),
				'adapter_request_id'   => sanitize_text_field( (string) ( $record['adapter_request_id'] ?? '' ) ),
				'execution_mode'       => sanitize_key( (string) ( $record['execution_mode'] ?? '' ) ),
				'selected_count'       => absint( $record['selected_count'] ?? 0 ),
				'submitted_count'      => absint( $record['submitted_count'] ?? 0 ),
				'executed_count'       => absint( $record['executed_count'] ?? 0 ),
				'failed_count'         => absint( $record['failed_count'] ?? 0 ),
				'blocked_count'        => absint( $record['blocked_count'] ?? 0 ),
				'partial_success'      => (bool) ( $record['partial_success'] ?? false ),
				'operator_next_action' => sanitize_key( (string) ( $record['operator_next_action'] ?? '' ) ),
				'error_code'           => sanitize_key( (string) ( $record['error_code'] ?? '' ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_data = $response->get_error_data();
			$error_data = is_array( $error_data ) ? $error_data : array();

			return array(
				'recorded'       => false,
				'status'         => 'failed',
				'error_code'     => sanitize_key( $response->get_error_code() ),
				'status_code'    => absint( $error_data['status'] ?? 0 ),
				'upstream_route' => sanitize_text_field( (string) ( $error_data['upstream_route'] ?? '' ) ),
			);
		}

		$data = $response->get_data();
		$data = is_array( $data ) ? $data : array();

		return array(
			'recorded'         => true,
			'status'           => sanitize_key( (string) ( $data['status'] ?? '' ) ),
			'proposal_id'      => sanitize_text_field( (string) ( $data['proposal_id'] ?? $proposal_id ) ),
			'ability_id'       => sanitize_text_field( (string) ( $data['ability_id'] ?? '' ) ),
			'updated_at'       => sanitize_text_field( (string) ( $data['updated_at'] ?? '' ) ),
			'commit_execution' => false,
		);
	}


	/**
	 * Runs a read-only ability through WordPress Abilities API.
	 *
	 * @param string              $ability_id Ability id.
	 * @param array<string,mixed> $input Ability input.
	 * @param array<string,mixed> $log_context AI request log context.
	 * @param array<string,mixed> $read_authorization Core read authorization request/context.
	 * @return WP_REST_Response|WP_Error
	 */
	private function run_read_ability( string $ability_id, array $input, array $log_context = array(), array $read_authorization = array() ) {
		$started    = microtime( true );
		$ability_id = sanitize_text_field( $ability_id );
		$capability = $this->find_core_capability( $ability_id );

		if ( is_wp_error( $capability ) ) {
			$this->emit_operation_event( 'adapter.ability.run_read', $started, $capability, array( 'ability_id' => $ability_id ) );
			return $capability;
		}

		$grant_context = array();
		if ( $this->core_read_authorization_required( $capability ) ) {
			$grant = $this->core_read_authorization_preflight( $capability, $input, $read_authorization );
			if ( is_wp_error( $grant ) ) {
				$this->emit_operation_event( 'adapter.ability.run_read', $started, $grant, array( 'ability_id' => $ability_id ) );
				return $grant;
			}
			$grant_context = $grant;
		}

		$governance_mode = (string) ( $capability['governance_mode'] ?? '' );
		if ( ! in_array( $governance_mode, array( 'direct_read', 'core_read_authorization_required' ), true ) || 'wp_abilities_rest' !== (string) ( $capability['execution_surface'] ?? '' ) ) {
			$error = new WP_Error(
				'npcink_openclaw_adapter_proposal_required',
				__( 'This ability is not direct-read. Create a Core proposal instead.', 'npcink-ai-client-adapter' ),
				array(
					'status'     => 403,
					'capability' => $this->public_capability_guidance( $capability ),
				)
			);
			$this->emit_operation_event( 'adapter.ability.run_read', $started, $error, array( 'ability_id' => $ability_id ) );
			return $error;
		}

		if ( true === (bool) ( $capability['core_proxy_execute'] ?? false ) || true === (bool) ( $capability['commit_execution'] ?? false ) ) {
			$error = new WP_Error(
				'npcink_openclaw_adapter_invalid_core_guidance',
				__( 'Core guidance unexpectedly allows proxy or commit execution.', 'npcink-ai-client-adapter' ),
				array( 'status' => 500 )
			);
			$this->emit_operation_event( 'adapter.ability.run_read', $started, $error, array( 'ability_id' => $ability_id ) );
			return $error;
		}

		$read_context = $this->read_governance_context( $capability, $log_context, $grant_context );
		$route        = '/wp-abilities/v1/abilities/' . $ability_id . '/run';
		$response     = $this->dispatch_upstream_with_request_log_context( $read_context, 'GET', $route, array( 'input' => $input ), true );

		if ( is_wp_error( $response ) ) {
			$this->emit_operation_event( 'adapter.ability.run_read', $started, $response, array( 'ability_id' => $ability_id ) );
			return $response;
		}

		$redacted = $this->apply_read_redaction( $response->get_data(), $read_context );
		$data     = $redacted['result'];

		$this->emit_operation_event(
			'adapter.ability.run_read',
			$started,
			null,
			array(
				'ability_id'         => $ability_id,
				'read_policy'        => (string) ( $read_context['read_policy'] ?? '' ),
				'sensitivity'        => (string) ( $read_context['sensitivity'] ?? '' ),
				'redaction_applied'  => (bool) ( $redacted['redaction_applied'] ?? false ),
				'correlation_id'     => (string) ( $read_context['correlation_id'] ?? '' ),
				'adapter_request_id' => (string) ( $read_context['adapter_request_id'] ?? '' ),
			)
		);

		return new WP_REST_Response(
			array(
				'ability_id'                 => $ability_id,
				'governance_mode'            => 'direct_read',
				'execution_surface'          => 'wp_abilities_rest',
				'core_proxy_execute'         => false,
				'commit_execution'           => false,
				'read_authorization_granted' => ! empty( $grant_context ),
				'read_policy'                => (string) ( $read_context['read_policy'] ?? '' ),
				'sensitivity'                => (string) ( $read_context['sensitivity'] ?? '' ),
				'redaction_required'         => (bool) ( $read_context['redaction_required'] ?? false ),
				'redaction_applied'          => (bool) ( $redacted['redaction_applied'] ?? false ),
				'redaction_summary'          => is_array( $redacted['redaction_summary'] ?? null ) ? $redacted['redaction_summary'] : array(),
				'read_audit_mode'            => (string) ( $read_context['read_audit_mode'] ?? '' ),
				'correlation_id'             => (string) ( $read_context['correlation_id'] ?? '' ),
				'log_context'                => $read_context,
				'read_context'               => $read_context,
				'result'                     => $data,
			),
			200
		);
	}

	/**
	 * Builds read governance context from Core capability guidance.
	 *
	 * @param array<string,mixed> $capability Capability row.
	 * @param array<string,mixed> $log_context Existing log context.
	 * @param array<string,mixed> $grant_context Core read authorization grant context.
	 * @return array<string,mixed>
	 */
	private function read_governance_context( array $capability, array $log_context, array $grant_context = array() ): array {
		$sensitivity = sanitize_key( (string) ( $capability['sensitivity'] ?? '' ) );
		if ( ! in_array( $sensitivity, array( 'public', 'internal', 'sensitive' ), true ) ) {
			$sensitivity = $this->infer_read_sensitivity( sanitize_text_field( (string) ( $capability['ability_id'] ?? '' ) ) );
		}

		$read_policy = sanitize_key( (string) ( $capability['read_policy'] ?? '' ) );
		if ( '' === $read_policy ) {
			$read_policy = 'direct_read_' . $sensitivity;
		}

		$log_context['read_policy']        = $read_policy;
		$log_context['sensitivity']        = $sensitivity;
		$log_context['redaction_required'] = ! empty( $grant_context )
			|| (bool) ( $capability['redaction_required'] ?? ( 'sensitive' === $sensitivity ) );
		$log_context['read_audit_mode']    = sanitize_key( (string) ( $capability['read_audit_mode'] ?? 'adapter_read_envelope' ) );
		$log_context['correlation_id']     = isset( $log_context['correlation_id'] ) && '' !== (string) $log_context['correlation_id']
			? sanitize_text_field( (string) $log_context['correlation_id'] )
			: wp_generate_uuid4();

		$npcink_governance_core                   = is_array( $log_context['npcink_governance_core'] ?? null ) ? $log_context['npcink_governance_core'] : array();
		$npcink_governance_core['correlation_id'] = $log_context['correlation_id'];
		if ( ! empty( $grant_context ) ) {
			$log_context['read_authorization_granted']          = true;
			$log_context['redaction_level']                     = sanitize_key( (string) ( $grant_context['redaction_level'] ?? 'strict' ) );
			$log_context['read_authorization_bounds']           = is_array( $grant_context['bounds'] ?? null ) ? $grant_context['bounds'] : array();
			$npcink_governance_core['read_request_id']          = sanitize_text_field( (string) ( $grant_context['request_id'] ?? '' ) );
			$npcink_governance_core['approved_input_hash']      = sanitize_text_field( (string) ( $grant_context['approved_input_hash'] ?? '' ) );
			$npcink_governance_core['core_authorization_truth'] = 'npcink_governance_core';
			$npcink_governance_core['commit_execution']         = false;
			$npcink_governance_core['write_execution']          = false;
		}
		$log_context['npcink_governance_core'] = $npcink_governance_core;

		return $this->sanitize_log_context( $log_context, true );
	}

	/**
	 * Returns whether Core requires an explicit read authorization before Adapter may run a direct-read ability.
	 *
	 * @param array<string,mixed> $capability Capability row.
	 * @return bool
	 */
	private function core_read_authorization_required( array $capability ): bool {
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

	/**
	 * Calls Core read-preflight and validates the returned grant.
	 *
	 * @param array<string,mixed> $capability Capability row.
	 * @param array<string,mixed> $input Ability input.
	 * @param array<string,mixed> $read_authorization Read authorization request/context.
	 * @return array<string,mixed>|WP_Error
	 */
	private function core_read_authorization_preflight( array $capability, array $input, array $read_authorization ) {
		$ability_id       = sanitize_text_field( (string) ( $capability['ability_id'] ?? '' ) );
		$request_id       = sanitize_text_field( (string) ( $read_authorization['request_id'] ?? '' ) );
		$expected_context = is_array( $read_authorization['read_authorization_context'] ?? null )
			? (array) $read_authorization['read_authorization_context']
			: array();
		if ( '' === $request_id && is_array( $expected_context ) ) {
			$request_id = sanitize_text_field( (string) ( $expected_context['request_id'] ?? '' ) );
		}

		if ( '' === $request_id ) {
			return $this->core_read_authorization_required_error( $capability );
		}

		$response = $this->dispatch_upstream(
			'POST',
			'/npcink-governance-core/v1/read-requests/' . rawurlencode( $request_id ) . '/read-preflight',
			array(
				'ability_id' => $ability_id,
				'input'      => $input,
			),
			false,
			true
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data    = $response->get_data();
		$context = is_array( $data ) && is_array( $data['read_authorization_context'] ?? null ) ? (array) $data['read_authorization_context'] : array();
		if ( empty( $context ) ) {
			return new WP_Error(
				'npcink_openclaw_adapter_core_read_grant_missing',
				__( 'Core read preflight did not return a read authorization context.', 'npcink-ai-client-adapter' ),
				array( 'status' => 502 )
			);
		}

		$validated = $this->validate_core_read_authorization_context( $context, $ability_id, $request_id );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( ! empty( $expected_context ) ) {
			$expected_hash = sanitize_text_field( (string) ( $expected_context['approved_input_hash'] ?? '' ) );
			$actual_hash   = sanitize_text_field( (string) ( $context['approved_input_hash'] ?? '' ) );
			if ( '' !== $expected_hash && $expected_hash !== $actual_hash ) {
				return new WP_Error(
					'npcink_openclaw_adapter_core_read_grant_hash_mismatch',
					__( 'Core read authorization context does not match the supplied approved input hash.', 'npcink-ai-client-adapter' ),
					array( 'status' => 403 )
				);
			}
		}

		return $validated;
	}

	/**
	 * Validates a Core read authorization context.
	 *
	 * @param array<string,mixed> $context Grant context.
	 * @param string              $ability_id Ability id.
	 * @param string              $request_id Request id.
	 * @return array<string,mixed>|WP_Error
	 */
	private function validate_core_read_authorization_context( array $context, string $ability_id, string $request_id ) {
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

		$site_binding = $this->validate_core_context_site_binding( $context, 'npcink_openclaw_adapter_core_read_grant', 403 );
		if ( is_wp_error( $site_binding ) ) {
			return $site_binding;
		}
		$client_binding = $this->validate_core_context_signed_client_binding( $context, 'npcink_openclaw_adapter_core_read_grant', 403 );
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

		return $this->sanitize_core_read_authorization_context( $context );
	}

	/**
	 * Sanitizes a Core read authorization context for runtime use and logs.
	 *
	 * @param array<string,mixed> $context Grant context.
	 * @return array<string,mixed>
	 */
	private function sanitize_core_read_authorization_context( array $context ): array {
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
			'data_classes'               => $this->sanitize_string_list( is_array( $context['data_classes'] ?? null ) ? (array) $context['data_classes'] : array() ),
			'redaction_level'            => sanitize_key( (string) ( $context['redaction_level'] ?? 'strict' ) ),
			'expires_at'                 => sanitize_text_field( (string) ( $context['expires_at'] ?? '' ) ),
			'bounds'                     => array(
				'max_rows'       => absint( $bounds['max_rows'] ?? 0 ),
				'tail_lines'     => absint( $bounds['tail_lines'] ?? 0 ),
				'allowed_fields' => $this->sanitize_string_list( is_array( $bounds['allowed_fields'] ?? null ) ? (array) $bounds['allowed_fields'] : array() ),
				'denied_fields'  => $this->sanitize_string_list( is_array( $bounds['denied_fields'] ?? null ) ? (array) $bounds['denied_fields'] : array() ),
				'one_time'       => ! empty( $bounds['one_time'] ),
			),
			'read_authorization_granted' => true,
			'core_authorization_truth'   => 'npcink_governance_core',
			'commit_execution'           => false,
			'write_execution'            => false,
		);
	}

	/**
	 * Builds the fail-closed response for Core-managed sensitive read authorization.
	 *
	 * @param array<string,mixed> $capability Capability row.
	 * @return WP_Error
	 */
	private function core_read_authorization_required_error( array $capability ): WP_Error {
		$read_policy = sanitize_key( (string) ( $capability['read_policy'] ?? 'core_read_authorization_required' ) );
		if ( '' === $read_policy ) {
			$read_policy = 'core_read_authorization_required';
		}

		return new WP_Error(
			'npcink_openclaw_adapter_core_read_authorization_required',
			__( 'Core requires explicit read authorization before Adapter may return this sensitive read result.', 'npcink-ai-client-adapter' ),
			array(
				'status'                      => 403,
				'ability_id'                  => sanitize_text_field( (string) ( $capability['ability_id'] ?? '' ) ),
				'sensitivity'                 => sanitize_key( (string) ( $capability['sensitivity'] ?? 'sensitive' ) ),
				'read_policy'                 => $read_policy,
				'read_authorization_required' => true,
				'required_flow'               => 'core_read_request',
				'core_authorization_truth'    => 'npcink_governance_core',
				'adapter_action'              => 'fail_closed',
				'next_steps'                  => array(
					__( 'Create or approve the sensitive read request in Npcink Governance Core.', 'npcink-ai-client-adapter' ),
					__( 'Retry only after Core exposes a bounded read authorization context for this ability and input.', 'npcink-ai-client-adapter' ),
					__( 'Do not bypass Adapter through the database, filesystem, logs, custom scripts, or direct WordPress internals.', 'npcink-ai-client-adapter' ),
				),
				'capability'                  => $this->public_capability_guidance( $capability ),
			)
		);
	}

	/**
	 * Applies read redaction according to Core read policy.
	 *
	 * @param mixed               $result Read result.
	 * @param array<string,mixed> $read_context Read context.
	 * @return array{result:mixed,redaction_applied:bool,redaction_summary:array<string,mixed>}
	 */
	private function apply_read_redaction( $result, array $read_context ): array {
		$required = (bool) ( $read_context['redaction_required'] ?? false );
		$count    = 0;
		$bounds   = is_array( $read_context['read_authorization_bounds'] ?? null ) ? (array) $read_context['read_authorization_bounds'] : array();

		if ( $required ) {
			$result = $this->apply_read_bounds( $result, $bounds, $count );
			$result = $this->redact_read_value( $result, $count, $this->sanitize_string_list( is_array( $bounds['denied_fields'] ?? null ) ? (array) $bounds['denied_fields'] : array() ) );
		}

		return array(
			'result'            => $result,
			'redaction_applied' => $required,
			'redaction_summary' => array(
				'policy_applied'       => $required,
				'redacted_field_count' => $count,
				'max_rows'             => absint( $bounds['max_rows'] ?? 0 ),
				'tail_lines'           => absint( $bounds['tail_lines'] ?? 0 ),
				'allowed_fields'       => $this->sanitize_string_list( is_array( $bounds['allowed_fields'] ?? null ) ? (array) $bounds['allowed_fields'] : array() ),
				'denied_fields'        => $this->sanitize_string_list( is_array( $bounds['denied_fields'] ?? null ) ? (array) $bounds['denied_fields'] : array() ),
			),
		);
	}

	/**
	 * Applies Core read bounds to a result tree.
	 *
	 * @param mixed               $value Value.
	 * @param array<string,mixed> $bounds Bounds.
	 * @param int                 $count Redaction count.
	 * @return mixed
	 */
	private function apply_read_bounds( $value, array $bounds, int &$count ) {
		$max_rows       = absint( $bounds['max_rows'] ?? 0 );
		$tail_lines     = absint( $bounds['tail_lines'] ?? 0 );
		$allowed_fields = $this->sanitize_string_list( is_array( $bounds['allowed_fields'] ?? null ) ? (array) $bounds['allowed_fields'] : array() );

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
					return $this->apply_read_bounds( $item, $bounds, $count );
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
			$clean[ $key ] = $this->apply_read_bounds( $item, $bounds, $count );
		}

		return $clean;
	}

	/**
	 * Redacts sensitive values in a read result.
	 *
	 * @param mixed $value Value.
	 * @param int   $count Redacted field count.
	 * @param array<int,string> $denied_fields Denied fields from Core.
	 * @return mixed
	 */
	private function redact_read_value( $value, int &$count, array $denied_fields = array() ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$denied_fields = array_map( 'strtolower', $denied_fields );
		$clean         = array();
		foreach ( $value as $key => $item ) {
			$key_string     = is_string( $key ) ? $key : (string) $key;
			$key_normalized = strtolower( $key_string );
			if ( 'authorization' === $key_normalized && ! in_array( $key_normalized, $denied_fields, true ) && $this->is_safe_governance_authorization_envelope( $item ) ) {
				$clean[ $key ] = $item;
				continue;
			}
			if ( in_array( $key_normalized, $denied_fields, true ) || $this->is_sensitive_read_key( $key_string ) ) {
				$clean[ $key ] = '[REDACTED]';
				++$count;
				continue;
			}

			$clean[ $key ] = $this->redact_read_value( $item, $count, $denied_fields );
		}

		return $clean;
	}

	/**
	 * Identifies the bounded non-secret authorization envelope used by Core-ready plans.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	private function is_safe_governance_authorization_envelope( $value ): bool {
		if ( ! is_array( $value ) || array() !== array_diff( array_keys( $value ), array( 'classification', 'authority' ) ) ) {
			return false;
		}

		return 'core_proposal_required' === ( $value['classification'] ?? null )
			&& 'npcink-governance-core' === ( $value['authority'] ?? null );
	}

	/**
	 * Returns whether an array is a list.
	 *
	 * @param array<mixed> $value Value.
	 * @return bool
	 */
	private function is_list_array( array $value ): bool {
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
	private function is_read_result_structural_key( string $key ): bool {
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
	private function is_sensitive_read_key( string $key ): bool {
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
	private function infer_read_sensitivity( string $ability_id ): string {
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
	 * Dispatches an upstream request while adding governance context to AI logs.
	 *
	 * @param array<string,mixed> $log_context Log context.
	 * @param string              $method HTTP method.
	 * @param string              $route REST route.
	 * @param array<string,mixed> $params Params.
	 * @param bool                $query_params Whether params should be query params.
	 * @return WP_REST_Response|WP_Error
	 */
	private function dispatch_upstream_with_request_log_context( array $log_context, string $method, string $route, array $params = array(), bool $query_params = false ) {
		if ( empty( $log_context ) ) {
			return $this->dispatch_upstream( $method, $route, $params, $query_params );
		}

		return $this->with_ai_request_log_context(
			$log_context,
			function () use ( $method, $route, $params, $query_params ) {
				return $this->dispatch_upstream( $method, $route, $params, $query_params );
			}
		);
	}

	/**
	 * Sends one upstream REST request with request-scoped identity context.
	 *
	 * @param string $method HTTP method.
	 * @param string $route REST route.
	 * @param array<string,mixed> $params Params.
	 * @param bool   $query_params Whether params should be query params.
	 * @param bool   $json_body Whether params should be encoded as JSON body.
	 * @param bool   $use_core_app_token Whether to attach the Core app token.
	 * @return WP_REST_Response|WP_Error
	 */
	private function dispatch_upstream( string $method, string $route, array $params = array(), bool $query_params = false, bool $json_body = false, bool $use_core_app_token = true ) {
		return $this->upstream_dispatch->send( $method, $route, $params, $query_params, $json_body, $use_core_app_token, $this->current_signed_client_fingerprint() );
	}

	/**
	 * Returns where the Core application token is configured.
	 *
	 * @return string
	 */
	private function core_app_token_source(): string {
		return $this->upstream_dispatch->core_app_token_source();
	}

	/**
	 * Returns whether a REST route is currently registered.
	 *
	 * @param string $route Route.
	 * @return bool
	 */
	private function rest_route_available( string $route ): bool {
		return $this->upstream_dispatch->rest_route_available( $route );
	}

	/**
	 * Finds one Core capability by ability id.
	 *
	 * @param string $ability_id Ability id.
	 * @return array<string,mixed>|WP_Error Capability row or discovery error.
	 */
	private function find_core_capability( string $ability_id ) {
		return $this->upstream_dispatch->find_core_capability( $ability_id );
	}

	/**
	 * Dispatches an upstream request with host approval runtime context.
	 *
	 * @param array<string,mixed> $runtime_context Runtime context.
	 * @param string              $method HTTP method.
	 * @param string              $route REST route.
	 * @param array<string,mixed> $params Params.
	 * @param bool                $query_params Whether params should be query params.
	 * @param bool                $json_body Whether params should be encoded as JSON body.
	 * @return WP_REST_Response|WP_Error
	 */
	private function dispatch_upstream_with_runtime_context( array $runtime_context, string $method, string $route, array $params = array(), bool $query_params = false, bool $json_body = false ) {
		$previous                                        = isset( $GLOBALS['npcink_ai_runtime_wp_ability_context'] ) ? $GLOBALS['npcink_ai_runtime_wp_ability_context'] : null;
		$GLOBALS['npcink_ai_runtime_wp_ability_context'] = array(
			'context' => $this->sanitize_runtime_context( $runtime_context ),
		);

		try {
			return $this->dispatch_upstream( $method, $route, $params, $query_params, $json_body );
		} finally {
			if ( null === $previous ) {
				unset( $GLOBALS['npcink_ai_runtime_wp_ability_context'] );
			} else {
				$GLOBALS['npcink_ai_runtime_wp_ability_context'] = $previous;
			}
		}
	}

	/**
	 * Runs a callback while adding adapter governance context to AI logs.
	 *
	 * @param array<string,mixed> $log_context Log context.
	 * @param callable            $callback Callback.
	 * @return mixed
	 */
	private function with_ai_request_log_context( array $log_context, callable $callback ) {
		$previous                          = $this->current_request_log_context;
		$this->current_request_log_context = $log_context;
		add_filter( 'wpai_request_log_context', array( $this, 'append_ai_request_log_context' ), 10, 3 );

		try {
			return $callback();
		} finally {
			remove_filter( 'wpai_request_log_context', array( $this, 'append_ai_request_log_context' ), 10 );
			$this->current_request_log_context = $previous;
		}
	}

	/**
	 * Adds adapter governance context to AI request logs.
	 *
	 * @param array<string,mixed> $context Existing log context.
	 * @param array<string,mixed> $decoded Decoded response.
	 * @param array<string,mixed> $log_data Full log data.
	 * @return array<string,mixed>
	 */
	public function append_ai_request_log_context( array $context, array $decoded = array(), array $log_data = array() ): array {
		if ( empty( $this->current_request_log_context ) ) {
			return $context;
		}

		$context['npcink_openclaw_adapter'] = $this->current_request_log_context;

		foreach ( array( 'proposal_id', 'correlation_id', 'ability_id', 'post_id', 'adapter_request_id', 'adapter_route', 'governance_source' ) as $key ) {
			if ( isset( $this->current_request_log_context[ $key ] ) ) {
				$context[ $key ] = $this->current_request_log_context[ $key ];
			}
		}

		if ( isset( $this->current_request_log_context['npcink_governance_core'] ) && is_array( $this->current_request_log_context['npcink_governance_core'] ) ) {
			$context['npcink_governance_core'] = $this->current_request_log_context['npcink_governance_core'];
		}

		return $context;
	}







	/**
	 * Returns suite dependency status for productized Adapter entry.
	 *
	 * @return array{items:array<string,array<string,mixed>>,missing:array<int,string>}
	 */
	private function dependency_status(): array {
		$items   = array(
			'npcink-governance-core'   => array(
				'label'        => 'Npcink Governance Core',
				'slug'         => 'npcink-governance-core',
				'slug_status'  => 'planned',
				'required_for' => array( 'capabilities', 'proposals', 'commit_preflight', 'approved_execution' ),
				'available'    => $this->rest_route_available( '/npcink-governance-core/v1/capabilities' ),
				'detector'     => 'rest_route:/npcink-governance-core/v1/capabilities',
			),
			'wordpress-abilities-api'  => array(
				'label'        => 'WordPress Abilities API',
				'slug'         => 'wordpress-core',
				'slug_status'  => 'platform',
				'required_for' => array( 'read_ability_execution', 'approved_execution' ),
				'available'    => $this->rest_route_available( '/wp-abilities/v1/abilities' ),
				'detector'     => 'rest_route:/wp-abilities/v1/abilities',
			),
			'npcink-abilities-toolkit' => array(
				'label'        => 'Npcink Abilities Toolkit',
				'slug'         => 'npcink-abilities-toolkit',
				'slug_status'  => 'wordpress_org_declared',
				'required_for' => array( 'reference_abilities', 'execution_profiles' ),
				'available'    => function_exists( 'npcink_abilities_toolkit_get_registered' ),
				'detector'     => 'function:npcink_abilities_toolkit_get_registered',
			),
		);
		$missing = array();
		foreach ( $items as $key => $item ) {
			if ( empty( $item['available'] ) ) {
				$missing[] = $key;
			}
		}

		return array(
			'items'   => $items,
			'missing' => $missing,
		);
	}

	/**
	 * Returns a structured dependency error for routes that cannot run alone.
	 *
	 * @param string $route REST route.
	 * @return WP_Error|null
	 */
	private function missing_dependency_for_route( string $route ): ?WP_Error {
		if ( 0 === strpos( $route, '/npcink-governance-core/v1/' ) && ! $this->rest_route_available( '/npcink-governance-core/v1/capabilities' ) ) {
			return new WP_Error(
				'npcink_openclaw_adapter_missing_dependency',
				__( 'Npcink Governance Core is required before Adapter can read capabilities, create proposals, or run commit preflight.', 'npcink-ai-client-adapter' ),
				array(
					'status'              => 503,
					'dependency'          => 'npcink-governance-core',
					'dependency_detector' => 'rest_route:/npcink-governance-core/v1/capabilities',
					'distribution_mode'   => 'adapter_entry_with_separate_governance_and_ability_plugins',
				)
			);
		}

		if ( 0 === strpos( $route, '/wp-abilities/v1/' ) && ! $this->rest_route_available( '/wp-abilities/v1/abilities' ) ) {
			return new WP_Error(
				'npcink_openclaw_adapter_missing_dependency',
				__( 'WordPress Abilities API is required before Adapter can execute read abilities or approved write abilities.', 'npcink-ai-client-adapter' ),
				array(
					'status'              => 503,
					'dependency'          => 'wordpress-abilities-api',
					'dependency_detector' => 'rest_route:/wp-abilities/v1/abilities',
					'distribution_mode'   => 'adapter_entry_with_separate_governance_and_ability_plugins',
				)
			);
		}

		return null;
	}

	/**
	 * Extracts an error status code with a fallback.
	 *
	 * @param WP_Error $error Error.
	 * @param int      $fallback Fallback status.
	 * @return int
	 */
	private function error_status_code( WP_Error $error, int $fallback ): int {
		$data = $error->get_error_data();
		return is_array( $data ) ? absint( $data['status'] ?? $fallback ) : $fallback;
	}

	/**
	 * Converts a rate-limit WP_Error into a REST response that also carries
	 * the standard Retry-After response header, so stock HTTP clients and
	 * SDKs honor the backoff without parsing the error body.
	 *
	 * @param WP_Error $error Rate limit error with retry_after in error data.
	 * @return WP_REST_Response
	 */
	private function rest_response_with_retry_after( WP_Error $error ): WP_REST_Response {
		$error_data = $error->get_error_data();
		$error_data = is_array( $error_data ) ? $error_data : array();

		$response = new WP_REST_Response(
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data'    => $error_data,
			),
			$this->error_status_code( $error, 429 )
		);

		$retry_after = absint( $error_data['retry_after'] ?? 0 );
		if ( $retry_after > 0 ) {
			$response->header( 'Retry-After', (string) $retry_after );
		}

		return $response;
	}




	/**
	 * Returns request input.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	private function request_input( WP_REST_Request $request ): array {
		return $this->object_param( $request, 'input' );
	}

	/**
	 * Returns Core read authorization parameters from a request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	private function read_authorization_params( WP_REST_Request $request ): array {
		$context    = $this->object_param( $request, 'read_authorization_context' );
		$request_id = sanitize_text_field( (string) $request->get_param( 'read_request_id' ) );
		if ( '' === $request_id && is_array( $context ) ) {
			$request_id = sanitize_text_field( (string) ( $context['request_id'] ?? '' ) );
		}

		return array(
			'request_id'                 => $request_id,
			'read_authorization_context' => $context,
		);
	}

	/**
	 * Returns governance context that should be copied into AI request logs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $ability_id Ability id.
	 * @return array<string,mixed>
	 */
	private function request_log_context( WP_REST_Request $request, string $ability_id ): array {
		$context = $this->client_log_context( $request );

		foreach ( array( 'proposal_id', 'correlation_id', 'external_thread_id', 'openclaw_thread_id', 'adapter_request_id', 'adapter_route' ) as $key ) {
			$value = $request->get_param( $key );
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$context[ $key ] = $value;
			}
		}

		$context['ability_id']         = sanitize_text_field( $ability_id );
		$context['adapter_request_id'] = isset( $context['adapter_request_id'] ) && '' !== (string) $context['adapter_request_id']
			? sanitize_text_field( (string) $context['adapter_request_id'] )
			: wp_generate_uuid4();
		$context['adapter_route']      = isset( $context['adapter_route'] ) && '' !== (string) $context['adapter_route']
			? sanitize_text_field( (string) $context['adapter_route'] )
			: $request->get_route();
		$context['governance_source']  = 'npcink-governance-core';
		$context['via']                = 'npcink-ai-client-adapter';

		$npcink_governance_core = is_array( $context['npcink_governance_core'] ?? null ) ? $context['npcink_governance_core'] : array();
		if ( isset( $context['proposal_id'] ) && '' !== (string) $context['proposal_id'] ) {
			$npcink_governance_core['proposal_id'] = $context['proposal_id'];
		}
		if ( isset( $context['correlation_id'] ) && '' !== (string) $context['correlation_id'] ) {
			$npcink_governance_core['correlation_id'] = $context['correlation_id'];
		}
		if ( ! empty( $npcink_governance_core ) ) {
			$context['npcink_governance_core'] = $npcink_governance_core;
		}

		return $this->sanitize_log_context( $context );
	}

	/**
	 * Returns only client-writable request log annotations.
	 *
	 * Governance, ability, authorization, and transport provenance fields are
	 * always derived by Adapter and cannot be supplied through log_context.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	private function client_log_context( WP_REST_Request $request ): array {
		$input = $this->object_param( $request, 'log_context' );
		$clean = array();

		foreach ( $this->client_annotation_fields() as $key ) {
			if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( (string) $input[ $key ] ) );
			if ( '' !== $value ) {
				$clean[ $key ] = $value;
			}
		}

		return $clean;
	}

	/**
	 * Returns untrusted client fields that may be kept as annotations.
	 *
	 * @return array<int,string>
	 */
	private function client_annotation_fields(): array {
		return array(
			'proposal_id',
			'correlation_id',
			'external_thread_id',
			'openclaw_thread_id',
			'adapter_request_id',
			'adapter_route',
		);
	}

	/**
	 * Returns caller metadata for Core proposal requests.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $ability_id Ability id.
	 * @return array<string,mixed>
	 */
	private function proposal_caller_context( WP_REST_Request $request, string $ability_id ): array {
		$caller      = array();
		$log_context = $this->request_log_context( $request, $ability_id );
		$input       = $this->object_param( $request, 'caller' );

		foreach ( $this->client_annotation_fields() as $key ) {
			$value = $input[ $key ] ?? ( $log_context[ $key ] ?? null );
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( (string) $value ) );
			if ( '' !== $value ) {
				$caller[ $key ] = $value;
			}
		}

		$caller['caller_type']       = 'openclaw_adapter';
		$caller['via']               = 'npcink-ai-client-adapter';
		$caller['ability_id']        = sanitize_text_field( $ability_id );
		$caller['governance_source'] = 'npcink-governance-core';

		$fingerprint = $this->current_signed_client_fingerprint();
		if ( '' !== $fingerprint ) {
			$caller['signed_client_fingerprint'] = $fingerprint;
		}

		return $this->sanitize_log_context( $caller );
	}

	/**
	 * Returns metadata-only context for observability events.
	 *
	 * @param WP_REST_Request     $request Request.
	 * @param array<string,mixed> $context Existing safe context.
	 * @return array<string,mixed>
	 */
	private function observability_request_context( WP_REST_Request $request, array $context = array() ): array {
		$context['method'] = $request->get_method();
		$context['route']  = $request->get_route();

		foreach ( array( 'proposal_id', 'correlation_id', 'adapter_request_id' ) as $key ) {
			$value = $request->get_param( $key );
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$context[ $key ] = $value;
			}
		}

		foreach ( array( 'log_context', 'caller' ) as $param ) {
			$value = $this->object_param( $request, $param );
			foreach ( array( 'proposal_id', 'correlation_id', 'adapter_request_id' ) as $key ) {
				if ( isset( $context[ $key ] ) && '' !== (string) $context[ $key ] ) {
					continue;
				}

				if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) && '' !== (string) $value[ $key ] ) {
					$context[ $key ] = $value[ $key ];
				}
			}
		}

		return $context;
	}

	/**
	 * Returns one object param.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $key Param key.
	 * @return array<string,mixed>
	 */
	private function object_param( WP_REST_Request $request, string $key ): array {
		$value = $request->get_param( $key );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Sanitizes a list of strings.
	 *
	 * @param array<mixed> $values Values.
	 * @return array<int,string>
	 */
	private function sanitize_string_list( array $values ): array {
		$clean = array();
		foreach ( $values as $value ) {
			$value = sanitize_text_field( (string) $value );
			if ( '' !== $value ) {
				$clean[] = $value;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Sanitizes AI request log context.
	 *
	 * @param mixed $value Context value.
	 * @param bool  $trusted_internal Whether Adapter-generated governance fields may be retained.
	 * @return mixed
	 */
	private function sanitize_log_context( $value, bool $trusted_internal = false ) {
		$field_count = 0;
		$clean       = $this->sanitize_log_context_value( $value, 0, $field_count, $trusted_internal );
		if ( ! is_array( $clean ) ) {
			return $clean;
		}

		while ( $this->serialized_log_context_bytes( $clean ) > self::MAX_LOG_CONTEXT_SERIALIZED_BYTES ) {
			if ( ! $this->remove_last_log_context_field( $clean ) ) {
				return array();
			}
		}

		return $clean;
	}

	/**
	 * Recursively sanitizes bounded log context.
	 *
	 * @param mixed $value Context value.
	 * @param int   $depth Current array depth.
	 * @param int   $field_count Global retained field count.
	 * @param bool  $trusted_internal Whether Adapter-generated governance fields may be retained.
	 * @return mixed
	 */
	private function sanitize_log_context_value( $value, int $depth, int &$field_count, bool $trusted_internal ) {
		if ( is_array( $value ) ) {
			if ( $depth > self::MAX_LOG_CONTEXT_DEPTH ) {
				return array();
			}

			$clean = array();
			foreach ( $value as $key => $item ) {
				if ( $field_count >= self::MAX_LOG_CONTEXT_FIELDS ) {
					break;
				}

				$clean_key = substr( sanitize_key( (string) $key ), 0, 64 );
				if ( '' === $clean_key || $this->is_sensitive_log_context_key( $clean_key, $trusted_internal ) ) {
					continue;
				}

				if ( is_array( $item ) && $depth >= self::MAX_LOG_CONTEXT_DEPTH ) {
					continue;
				}

				++$field_count;
				$clean[ $clean_key ] = $this->sanitize_log_context_value( $item, $depth + 1, $field_count, $trusted_internal );
			}

			return $clean;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		$value = sanitize_text_field( wp_unslash( (string) $value ) );
		if ( strlen( $value ) <= self::MAX_LOG_CONTEXT_STRING_BYTES ) {
			return $value;
		}

		if ( function_exists( 'mb_strcut' ) ) {
			return mb_strcut( $value, 0, self::MAX_LOG_CONTEXT_STRING_BYTES, 'UTF-8' );
		}

		return substr( $value, 0, self::MAX_LOG_CONTEXT_STRING_BYTES );
	}

	/**
	 * Returns whether a log context key may hold secret-bearing material.
	 *
	 * @param string $key Sanitized key.
	 * @param bool   $trusted_internal Whether Adapter-generated governance fields may be retained.
	 * @return bool
	 */
	private function is_sensitive_log_context_key( string $key, bool $trusted_internal ): bool {
		if (
			$trusted_internal
			&& in_array(
				$key,
				array(
					'read_authorization_granted',
					'read_authorization_bounds',
					'core_authorization_truth',
				),
				true
			)
		) {
			return false;
		}

		return 1 === preg_match( '/password|passwd|token|secret|authorization|cookie|nonce|signature|private[-_]?key|api[-_]?key|credential/i', $key );
	}

	/**
	 * Returns serialized log context size.
	 *
	 * @param array<string,mixed> $value Log context.
	 * @return int
	 */
	private function serialized_log_context_bytes( array $value ): int {
		$encoded = wp_json_encode( $value );
		return is_string( $encoded ) ? strlen( $encoded ) : PHP_INT_MAX;
	}

	/**
	 * Removes the final retained field from a nested context.
	 *
	 * @param array<string,mixed> $value Log context mutated in place.
	 * @return bool
	 */
	private function remove_last_log_context_field( array &$value ): bool {
		$keys = array_keys( $value );
		for ( $index = count( $keys ) - 1; $index >= 0; --$index ) {
			$key = $keys[ $index ];
			if ( is_array( $value[ $key ] ) && ! empty( $value[ $key ] ) ) {
				$child = $value[ $key ];
				if ( $this->remove_last_log_context_field( $child ) ) {
					if ( empty( $child ) ) {
						unset( $value[ $key ] );
					} else {
						$value[ $key ] = $child;
					}
					return true;
				}
			}

			unset( $value[ $key ] );
			return true;
		}

		return false;
	}

	/**
	 * Sanitizes host runtime context while preserving field names.
	 *
	 * @param mixed $value Runtime context value.
	 * @return mixed
	 */
	private function sanitize_runtime_context( $value ) {
		if ( is_array( $value ) ) {
			$clean = array();
			foreach ( $value as $key => $item ) {
				$clean[ sanitize_key( (string) $key ) ] = $this->sanitize_runtime_context( $item );
			}
			return $clean;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		return sanitize_text_field( wp_unslash( (string) $value ) );
	}

	/**
	 * Returns public capability guidance fields.
	 *
	 * @param array<string,mixed> $capability Capability row.
	 * @return array<string,mixed>
	 */
	private function public_capability_guidance( array $capability ): array {
		return array(
			'ability_id'                  => (string) ( $capability['ability_id'] ?? '' ),
			'risk_level'                  => (string) ( $capability['risk_level'] ?? '' ),
			'requires_approval'           => (bool) ( $capability['requires_approval'] ?? false ),
			'governance_mode'             => (string) ( $capability['governance_mode'] ?? '' ),
			'execution_surface'           => (string) ( $capability['execution_surface'] ?? '' ),
			'read_policy'                 => (string) ( $capability['read_policy'] ?? '' ),
			'sensitivity'                 => (string) ( $capability['sensitivity'] ?? '' ),
			'read_authorization_required' => $this->core_read_authorization_required( $capability ),
			'core_proxy_execute'          => (bool) ( $capability['core_proxy_execute'] ?? false ),
			'commit_execution'            => (bool) ( $capability['commit_execution'] ?? false ),
		);
	}

	/**
	 * Emits a metadata-only operation event.
	 *
	 * @param string              $event_kind Event kind.
	 * @param float               $started Start time.
	 * @param WP_Error|null       $error Error result.
	 * @param array<string,mixed> $context Safe context fields.
	 * @return void
	 */
	private function emit_operation_event( string $event_kind, float $started, $error, array $context = array() ): void {
		if ( is_wp_error( $error ) ) {
			if ( ! isset( $context['status_code'] ) ) {
				$error_data = $this->error_data_array( $error );
				if ( isset( $error_data['status'] ) ) {
					$context['status_code'] = absint( $error_data['status'] );
				}
			}

			if ( ! isset( $context['status_detail'] ) ) {
				$context['status_detail'] = $error->get_error_code();
			}
		}

		$status     = is_wp_error( $error ) ? 'error' : 'ok';
		$error_code = is_wp_error( $error ) ? (string) $error->get_error_code() : '';

		Observability::emit(
			$event_kind,
			array_merge(
				array(
					'status'     => $status,
					'event_id'   => $this->operation_event_id( $event_kind, $status, $error_code, $context ),
					'error_code' => $error_code,
					'latency_ms' => max( 0, (int) round( ( microtime( true ) - $started ) * 1000 ) ),
				),
				$this->safe_observability_context( $context )
			)
		);
	}

	/**
	 * Keeps operation observability payloads metadata-only and bounded.
	 *
	 * @param array<string,mixed> $context Candidate context.
	 * @return array<string,mixed>
	 */
	private function safe_observability_context( array $context ): array {
		$safe = array();

		foreach ( array( 'method', 'route', 'ability_id', 'proposal_id', 'correlation_id', 'adapter_request_id', 'status_detail', 'read_policy', 'sensitivity' ) as $key ) {
			if ( ! isset( $context[ $key ] ) || ! is_scalar( $context[ $key ] ) ) {
				continue;
			}

			$value = sanitize_text_field( (string) $context[ $key ] );
			if ( '' === $value ) {
				continue;
			}

			if ( 'method' === $key ) {
				$value = strtoupper( sanitize_key( $value ) );
			} elseif ( 'status_detail' === $key ) {
				$value = sanitize_key( $value );
			}

			$safe[ $key ] = substr( $value, 0, 200 );
		}

		foreach ( array( 'status_code', 'proposal_count', 'blocked_count', 'executed_count', 'failed_count' ) as $key ) {
			if ( isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) ) {
				$safe[ $key ] = max( 0, absint( $context[ $key ] ) );
			}
		}

		foreach ( array( 'redaction_applied' ) as $key ) {
			if ( isset( $context[ $key ] ) ) {
				$safe[ $key ] = (bool) $context[ $key ];
			}
		}

			return $safe;
	}

		/**
		 * Builds a stable metadata-only event id for operation dedupe.
		 *
		 * @param string              $event_kind Event kind.
		 * @param string              $status Event status.
		 * @param string              $error_code Error code, when present.
		 * @param array<string,mixed> $context Metadata-only event context.
		 * @return string
		 */
	private function operation_event_id( string $event_kind, string $status, string $error_code, array $context ): string {
		$identity = array(
			'event_kind'         => $event_kind,
			'status'             => $status,
			'error_code'         => $error_code,
			'method'             => (string) ( $context['method'] ?? '' ),
			'route'              => (string) ( $context['route'] ?? '' ),
			'status_code'        => (int) ( $context['status_code'] ?? 0 ),
			'ability_id'         => (string) ( $context['ability_id'] ?? '' ),
			'proposal_id'        => (string) ( $context['proposal_id'] ?? '' ),
			'correlation_id'     => (string) ( $context['correlation_id'] ?? '' ),
			'adapter_request_id' => (string) ( $context['adapter_request_id'] ?? '' ),
			'proposal_count'     => (int) ( $context['proposal_count'] ?? 0 ),
			'blocked_count'      => (int) ( $context['blocked_count'] ?? 0 ),
			'executed_count'     => (int) ( $context['executed_count'] ?? 0 ),
			'failed_count'       => (int) ( $context['failed_count'] ?? 0 ),
		);
		$json     = function_exists( 'wp_json_encode' ) ? wp_json_encode( $identity ) : json_encode( $identity );
		$hash     = hash( 'sha256', is_string( $json ) ? $json : '' );
		$prefix   = sanitize_key( str_replace( '.', '_', $event_kind ) );

		return $prefix . '_' . substr( $hash, 0, 32 );
	}
}
