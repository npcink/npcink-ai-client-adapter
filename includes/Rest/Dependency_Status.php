<?php
/**
 * Core and Toolkit dependency introspection domain service.
 *
 * Owns live dependency readiness detection, per-route dependency gating,
 * and the runtime contract summaries surfaced on health and help. Route
 * registration state is read through the upstream dispatch service; the
 * request-local contract cache lives here.
 *
 * @package NpcinkOpenClawAdapter
 */

namespace Npcink\OpenClawAdapter\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects dependency readiness and summarizes dependency contracts.
 */
final class Dependency_Status {

	/**
	 * Upstream dispatch service for live route availability.
	 *
	 * @var Upstream_Dispatch
	 */
	private $upstream_dispatch;

	/**
	 * Request-local dependency contract cache.
	 *
	 * @var array<string,mixed>|null
	 */
	private $contracts_cache = null;

	/**
	 * Creates the dependency introspection service.
	 *
	 * @param Upstream_Dispatch $upstream_dispatch Upstream dispatch service.
	 */
	public function __construct( Upstream_Dispatch $upstream_dispatch ) {
		$this->upstream_dispatch = $upstream_dispatch;
	}

	/**
	 * Sanitizes a list of strings.
	 *
	 * @param array<mixed> $values Values.
	 * @return array<int,string>
	 */
	public function sanitize_string_list( array $values ): array {
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
	 * Extracts a REST error code from response data.
	 *
	 * @param mixed $data Response data.
	 * @return string
	 */
	public function rest_error_code_from_data( $data ): string {
		if ( is_array( $data ) && is_scalar( $data['code'] ?? null ) ) {
			return sanitize_key( (string) $data['code'] );
		}

		return 'dependency_contract_unavailable';
	}

	/**
	 * Returns a structured dependency error for routes that cannot run alone.
	 *
	 * @param string $route REST route.
	 * @return WP_Error|null
	 */
	public function missing_for_route( string $route ): ?WP_Error {
		if ( 0 === strpos( $route, '/npcink-governance-core/v1/' ) && ! $this->upstream_dispatch->rest_route_available( '/npcink-governance-core/v1/capabilities' ) ) {
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

		if ( 0 === strpos( $route, '/wp-abilities/v1/' ) && ! $this->upstream_dispatch->rest_route_available( '/wp-abilities/v1/abilities' ) ) {
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
	 * Returns suite dependency status for productized Adapter entry.
	 *
	 * @return array{items:array<string,array<string,mixed>>,missing:array<int,string>}
	 */
	public function status(): array {
		$items   = array(
			'npcink-governance-core'   => array(
				'label'        => 'Npcink Governance Core',
				'slug'         => 'npcink-governance-core',
				'slug_status'  => 'planned',
				'required_for' => array( 'capabilities', 'proposals', 'commit_preflight', 'approved_execution' ),
				'available'    => $this->upstream_dispatch->rest_route_available( '/npcink-governance-core/v1/capabilities' ),
				'detector'     => 'rest_route:/npcink-governance-core/v1/capabilities',
			),
			'wordpress-abilities-api'  => array(
				'label'        => 'WordPress Abilities API',
				'slug'         => 'wordpress-core',
				'slug_status'  => 'platform',
				'required_for' => array( 'read_ability_execution', 'approved_execution' ),
				'available'    => $this->upstream_dispatch->rest_route_available( '/wp-abilities/v1/abilities' ),
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
	 * Returns safe boundary fields from a dependency contract.
	 *
	 * @param string              $dependency Dependency key.
	 * @param array<string,mixed> $contract Dependency contract.
	 * @return array<string,mixed>
	 */
	public function contract_boundary_summary( string $dependency, array $contract ): array {
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
	public function contract_summary( string $dependency, string $route, string $expected_schema, string $contract_version_key, string $min_contract_version, string $min_plugin_version ): array {
		if ( ! $this->upstream_dispatch->rest_route_available( $route ) ) {
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
		$boundary_summary    = $this->contract_boundary_summary( $dependency, $data );
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
	 * Returns detected Core and Toolkit runtime contract summaries.
	 *
	 * @return array<string,mixed>
	 */
	public function contracts(): array {
		if ( is_array( $this->contracts_cache ) ) {
			return $this->contracts_cache;
		}

		$cached = get_transient( 'npcink_openclaw_adapter_dependency_contracts_v1' );
		if ( is_array( $cached ) ) {
			$this->contracts_cache = $cached;
			return $cached;
		}

		$core    = $this->contract_summary(
			'npcink-governance-core',
			'/npcink-governance-core/v1/contract',
			'npcink_governance_core_contract.v1',
			'core_contract_version',
			Controller::CORE_CONTRACT_MIN_VERSION,
			Controller::CORE_PLUGIN_MIN_VERSION
		);
		$toolkit = $this->contract_summary(
			'npcink-abilities-toolkit',
			'/npcink-abilities-toolkit/v1/contract',
			'npcink_abilities_toolkit_contract.v1',
			'toolkit_contract_version',
			Controller::TOOLKIT_CONTRACT_MIN_VERSION,
			Controller::TOOLKIT_PLUGIN_MIN_VERSION
		);

		$contracts = array(
			'ready'                    => ! empty( $core['compatible'] ) && ! empty( $toolkit['compatible'] ),
			'npcink-governance-core'   => $core,
			'npcink-abilities-toolkit' => $toolkit,
		);

		$this->contracts_cache = $contracts;
		set_transient( 'npcink_openclaw_adapter_dependency_contracts_v1', $contracts, Controller::DISCOVERY_CACHE_TTL );

		return $contracts;
	}
}
