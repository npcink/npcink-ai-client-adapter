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
	const DISCOVERY_CACHE_TTL                  = Upstream_Dispatch::DISCOVERY_CACHE_TTL;
	const PREFLIGHT_HANDOFF_RETENTION_TTL      = 900;
	const EXECUTION_RECORD_RETENTION_TTL       = 604800;
	const MAX_UPSTREAM_ERROR_DETAIL_BYTES      = Upstream_Dispatch::MAX_UPSTREAM_ERROR_DETAIL_BYTES;
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
	 * Core and Toolkit dependency introspection service.
	 *
	 * @var Dependency_Status
	 */
	private $dependency_status;

	/**
	 * Core commit-preflight handoff storage and binding validation service.
	 *
	 * @var Preflight_Handoffs
	 */
	private $preflight_handoffs;

	/**
	 * Proposal review loop service: input gates and operator feedback.
	 *
	 * @var Proposal_Review
	 */
	private $proposal_review;

	/**
	 * Read governance service: sensitivity, redaction, context validation.
	 *
	 * @var Read_Governance
	 */
	private $read_governance;

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
		$this->dependency_status         = new Dependency_Status( $this->upstream_dispatch );
		$this->preflight_handoffs        = new Preflight_Handoffs(
			$this->execution_records,
			$this->signing_auth,
			function (): string {
				return $this->current_signed_client_fingerprint();
			}
		);
		$this->execution_input_validator = new Execution_Input_Validator( self::execution_profiles() );
		$this->proposal_review           = new Proposal_Review( $this->execution_input_validator );
		$this->read_governance           = new Read_Governance( $this->dependency_status, $this->signing_auth, $this->preflight_handoffs );
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
						'offset' => array(
							'type'              => 'integer',
							'default'           => 0,
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
						'limit'  => array(
							'type'              => 'integer',
							'default'           => 50,
							'sanitize_callback' => 'absint',
						),
						'offset' => array(
							'type'              => 'integer',
							'default'           => 0,
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

	/**
	 * Validates one proposal create input against the profile rules.
	 *
	 * @param string              $ability_id Ability id.
	 * @param array<string,mixed> $input Ability input.
	 * @param bool                $allow_output_refs Whether output references may be used.
	 * @param int|null            $action_index Plan action index.
	 * @return true|WP_Error
	 */
	private function validate_proposal_create_input( string $ability_id, array $input, bool $allow_output_refs = false, ?int $action_index = null ) {
		return $this->proposal_review->validate_create_input( $ability_id, $input, $allow_output_refs, $action_index );
	}

	/**
	 * Normalizes plan batch metadata before Core forwarding.
	 *
	 * @param array<string,mixed> $plan_payload Plan payload.
	 * @return array<string,mixed>
	 */
	private function normalize_plan_batch_metadata( array $plan_payload ): array {
		return $this->proposal_review->normalize_batch_metadata( $plan_payload );
	}

	/**
	 * Validates plan write action inputs before Core forwarding.
	 *
	 * @param array<string,mixed> $plan_payload Plan payload.
	 * @return true|WP_Error
	 */
	private function validate_plan_write_action_inputs( array $plan_payload ) {
		return $this->proposal_review->validate_plan_write_actions( $plan_payload );
	}

	/**
	 * Extracts the data array of a WP_Error.
	 *
	 * @param WP_Error $error Error.
	 * @return array<string,mixed>
	 */
	private function error_data_array( WP_Error $error ): array {
		return $this->proposal_review->error_data( $error );
	}

	/**
	 * Builds batch review feedback from Core proposal rejections.
	 *
	 * @param array<string,mixed> $data Response data.
	 * @return array<string,mixed>
	 */
	private function batch_review_feedback_from_proposals( array $data ): array {
		return $this->proposal_review->feedback_from_proposals( $data );
	}

	/**
	 * Builds batch review feedback from a Core preflight response.
	 *
	 * @param array<string,mixed> $preflight Preflight response.
	 * @param array<string,mixed> $proposal Core proposal.
	 * @return array<string,mixed>
	 */
	private function batch_review_feedback_from_preflight( array $preflight, array $proposal = array() ): array {
		return $this->proposal_review->feedback_from_preflight( $preflight, $proposal );
	}

	/**
	 * Builds operator feedback for a failed plan handoff.
	 *
	 * @param WP_Error $error Error.
	 * @param string   $plan_ability_id Plan ability id.
	 * @return array<string,mixed>
	 */
	private function plan_handoff_operator_feedback( WP_Error $error, string $plan_ability_id ): array {
		return $this->proposal_review->plan_handoff_feedback( $error, $plan_ability_id );
	}

	/**
	 * Builds operator feedback for a proposal status transition.
	 *
	 * @param array<string,mixed> $proposal Core proposal.
	 * @param string              $status_before Previous status.
	 * @return array<string,mixed>
	 */
	private function proposal_status_operator_feedback( array $proposal, string $status_before ): array {
		return $this->proposal_review->proposal_status_feedback( $proposal, $status_before );
	}

	/**
	 * Builds operator feedback for a commit preflight outcome.
	 *
	 * @param WP_Error|null       $error Preflight error or null.
	 * @param array<string,mixed> $proposal Core proposal.
	 * @param array<string,mixed> $preflight Preflight response.
	 * @return array<string,mixed>
	 */
	private function preflight_operator_feedback( ?WP_Error $error, array $proposal, array $preflight = array() ): array {
		return $this->proposal_review->preflight_feedback( $error, $proposal, $preflight );
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
	 * Returns detected Core and Toolkit runtime contract summaries.
	 *
	 * @return array<string,mixed>
	 */
	private function dependency_contracts(): array {
		return $this->dependency_status->contracts();
	}

	/**
	 * Returns live dependency readiness status.
	 *
	 * @return array<string,mixed>
	 */
	private function dependency_status(): array {
		return $this->dependency_status->status();
	}

	/**
	 * Returns the missing-dependency error for a route, or null when ready.
	 *
	 * @param string $route Route.
	 * @return WP_Error|null
	 */
	private function missing_dependency_for_route( string $route ): ?WP_Error {
		return $this->dependency_status->missing_for_route( $route );
	}

	/**
	 * Sanitizes a list of scalar strings.
	 *
	 * @param array<mixed> $values Values.
	 * @return array<int,string>
	 */
	private function sanitize_string_list( array $values ): array {
		return $this->dependency_status->sanitize_string_list( $values );
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
	 * Applies governed redaction and bounds to a read result.
	 *
	 * @param mixed              $result Read result.
	 * @param array<string,mixed> $read_context Read context.
	 * @return array<string,mixed>
	 */
	private function apply_read_redaction( $result, array $read_context ): array {
		return $this->read_governance->apply_redaction( $result, $read_context );
	}

	/**
	 * Returns whether a capability requires the Core read-request flow.
	 *
	 * @param array<string,mixed> $capability Core capability.
	 * @return bool
	 */
	private function core_read_authorization_required( array $capability ): bool {
		return $this->read_governance->authorization_required( $capability );
	}

	/**
	 * Validates a Core read authorization context.
	 *
	 * @param array<string,mixed> $context Context.
	 * @param string              $ability_id Ability id.
	 * @param string              $request_id Read request id.
	 * @return array<string,mixed>|WP_Error Sanitized context or validation error.
	 */
	private function validate_core_read_authorization_context( array $context, string $ability_id, string $request_id ) {
		return $this->read_governance->validate_authorization_context( $context, $ability_id, $request_id );
	}

	/**
	 * Infers the read sensitivity classification for an ability id.
	 *
	 * @param string $ability_id Ability id.
	 * @return string
	 */
	private function infer_read_sensitivity( string $ability_id ): string {
		return $this->read_governance->infer_sensitivity( $ability_id );
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
		return $this->relay_list_proxy_result(
			$this->dispatch_upstream(
				'GET',
				'/npcink-governance-core/v1/read-requests',
				array(
					'limit'  => min( self::MAX_PROPOSAL_LIST_LIMIT, max( 1, absint( $request->get_param( 'limit' ) ) ) ),
					'offset' => max( 0, absint( $request->get_param( 'offset' ) ) ),
					'status' => sanitize_key( (string) $request->get_param( 'status' ) ),
				),
				true
			)
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

		return $this->relay_list_proxy_result(
			$this->dispatch_upstream(
				'GET',
				'/npcink-governance-core/v1/proposals',
				array(
					'limit'  => $limit,
					'offset' => max( 0, absint( $request->get_param( 'offset' ) ) ),
				),
				true
			)
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
	 * Runs Core commit preflight.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function commit_preflight( WP_REST_Request $request ) {
		$started     = microtime( true );
		$proposal_id = (string) $request->get_param( 'proposal_id' );
		$relay_proposal = $this->get_core_proposal_data( $proposal_id );
		$relay_params   = array();
		if ( ! is_wp_error( $relay_proposal ) ) {
			$relay_actions = $this->normalize_execution_actions( $proposal_id, $relay_proposal );
			if ( ! is_wp_error( $relay_actions ) ) {
				$relay_verification_reads = $this->verification_reads_for_actions( $relay_actions );
				if ( ! empty( $relay_verification_reads ) ) {
					$relay_params['verification_reads'] = $relay_verification_reads;
				}
			}
		}
		$response    = $this->dispatch_upstream(
			'POST',
			'/npcink-governance-core/v1/proposals/' . rawurlencode( $proposal_id ) . '/commit-preflight',
			$relay_params,
			false,
			true
		);
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
		$verification_read_authorization     = array();
		$verification_grants                 = is_array( $base_request_context['verification_read_requests'] ?? null ) ? $base_request_context['verification_read_requests'] : array();
		$verification_candidates             = array();
		$resolved_post_id                    = isset( $read_input['post_id'] ) && is_numeric( $read_input['post_id'] ) ? (int) $read_input['post_id'] : 0;
		if ( $resolved_post_id > 0 ) {
			$verification_candidates[] = 'post:' . $resolved_post_id;
		}
		$resolved_slug = isset( $slug ) && is_string( $slug ) ? sanitize_key( $slug ) : '';
		if ( '' !== $resolved_slug ) {
			$verification_candidates[] = 'slug:' . $resolved_slug;
		}
		foreach ( $verification_candidates as $verification_candidate ) {
			if ( ! isset( $verification_grants[ $read_ability_id . '|' . $verification_candidate ] ) ) {
				continue;
			}
			$verification_grant = $verification_grants[ $read_ability_id . '|' . $verification_candidate ];
			$request_id         = sanitize_text_field( (string) ( $verification_grant['request_id'] ?? '' ) );
			if ( '' === $request_id ) {
				continue;
			}
			$verification_read_authorization['request_id'] = $request_id;
			$grant_input = is_array( $verification_grant['input'] ?? null ) ? $verification_grant['input'] : array();
			if ( isset( $grant_input['post_id'] ) && is_numeric( $grant_input['post_id'] ) && (int) $grant_input['post_id'] > 0 && $resolved_post_id > 0 ) {
				// Same object by definition; keep the resolved id for the read.
				$grant_input['post_id'] = $resolved_post_id;
				$read_input = $grant_input;
			} elseif ( isset( $grant_input['slug'] ) && '' !== $resolved_slug ) {
				// Slug-addressed grant (for example upsert-template-blocks): read by the approved slug.
				$read_input = $grant_input;
			}
			break;
		}
		$response                            = $this->run_read_ability( $read_ability_id, $read_input, $read_context, $verification_read_authorization );
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
		$lock = $this->acquire_execution_lock( $proposal_id );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			return $this->execute_core_approved_proposal_locked( $request, $proposal_id, $proposal );
		} finally {
			$this->release_execution_lock( $lock[0], $lock[1] );
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
			$preflight_response = $this->dispatch_upstream(
				'POST',
				'/npcink-governance-core/v1/proposals/' . rawurlencode( $proposal_id ) . '/commit-preflight',
				array( 'verification_reads' => $this->verification_reads_for_actions( $actions ) ),
				false,
				true
			);
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
		$base_request_context['verification_read_requests'] = $this->verification_read_grant_map( $preflight );

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
	 * @return array{0:string,1:string}|WP_Error Array of lock key and lock token, or lock contention error.
	 */
	private function acquire_execution_lock( string $proposal_id ) {
		return $this->execution_records->acquire_lock( $proposal_id );
	}

	/**
	 * Releases an execution lock.
	 *
	 * @param string $lock_key Lock key.
	 * @param string $lock_token Lock token from acquisition.
	 * @return void
	 */
	private function release_execution_lock( string $lock_key, string $lock_token ): void {
		$this->execution_records->release_lock( $lock_key, $lock_token );
	}

	/**
	 * Stores one Core commit-preflight handoff for the next execute call.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param array<string,mixed> $proposal Core proposal.
	 * @param array<string,mixed> $preflight Core preflight response.
	 * @return array<string,mixed>|null Stored handoff or null.
	 */
	private function store_preflight_handoff( string $proposal_id, array $proposal, array $preflight ): ?array {
		return $this->preflight_handoffs->store( $proposal_id, $proposal, $preflight );
	}

	/**
	 * Consumes the cached preflight handoff for an execution attempt.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param array<string,mixed> $proposal Core proposal.
	 * @return array<string,mixed>|null Handoff or null.
	 */
	private function consume_cached_preflight_handoff( string $proposal_id, array $proposal ): ?array {
		return $this->preflight_handoffs->consume( $proposal_id, $proposal );
	}

	/**
	 * Returns the cached handoff projection for proposal status responses.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param array<string,mixed> $proposal Core proposal.
	 * @return array<string,mixed>|null Handoff projection or null.
	 */
	private function cached_preflight_handoff_for_status( string $proposal_id, array $proposal ): ?array {
		return $this->preflight_handoffs->cached_for_status( $proposal_id, $proposal );
	}

	/**
	 * Validates the full preflight-to-proposal binding before execution.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param array<string,mixed> $proposal Core proposal.
	 * @param array<string,mixed> $preflight Core preflight response.
	 * @return WP_Error|true Binding error or true when the binding holds.
	 */
	private function validate_preflight_binding( string $proposal_id, array $proposal, array $preflight ) {
		return $this->preflight_handoffs->validate_binding( $proposal_id, $proposal, $preflight );
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
	 * Returns the verification reads the post-execution readback will need.
	 *
	 * Mirrors block_write_readback_verification()'s write-to-read pairing,
	 * derived from each normalized action's write input so Core can mint single-use
	 * verification read requests at commit preflight (Core ADR-011).
	 *
	 * @param array<int,array<string,mixed>> $actions Normalized execution actions.
	 * @return array<int,array<string,mixed>> Verification read requests.
	 */
	private function verification_reads_for_actions( array $actions ): array {
		$reads = array();

		foreach ( (array) $actions as $action ) {
			$action    = is_array( $action ) ? $action : array();
			$ability_id = sanitize_text_field( (string) ( $action['ability_id'] ?? '' ) );
			$input      = is_array( $action['input'] ?? null ) ? $action['input'] : array();
			$post_id    = isset( $input['post_id'] ) && is_numeric( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
			$slug       = isset( $input['slug'] ) && is_string( $input['slug'] ) ? sanitize_key( $input['slug'] ) : '';

			if ( 'npcink-abilities-toolkit/update-post-blocks' === $ability_id && $post_id > 0 ) {
				$reads[] = array(
					'ability_id' => 'npcink-abilities-toolkit/get-post-blocks',
					'input'      => array(
						'post_id'              => $post_id,
						'include_inner_blocks' => true,
					),
				);
				continue;
			}

			if ( ( 'npcink-abilities-toolkit/update-template-blocks' === $ability_id || 'npcink-abilities-toolkit/upsert-template-blocks' === $ability_id ) && ( $post_id > 0 || '' !== $slug ) ) {
				$reads[] = array(
					'ability_id' => 'npcink-abilities-toolkit/get-template-blocks',
					'input'      => $post_id > 0 ? array( 'post_id' => $post_id ) : array( 'slug' => $slug ),
				);
				continue;
			}

			if ( 'npcink-abilities-toolkit/update-template-part-blocks' === $ability_id && ( $post_id > 0 || '' !== $slug ) ) {
				$reads[] = array(
					'ability_id' => 'npcink-abilities-toolkit/get-template-part-blocks',
					'input'      => $post_id > 0 ? array( 'post_id' => $post_id ) : array( 'slug' => $slug ),
				);
			}
		}

		return $reads;
	}

	/**
	 * Returns the grant map from a preflight response.
	 *
	 * Keyed by read ability plus the object signature the minted input
	 * addresses, so multiple actions pairing to the same read ability keep
	 * their own single-use grants. Each entry carries the minted input so
	 * the readback can present exactly the approved addressing.
	 *
	 * @param array<string,mixed> $preflight Commit preflight response.
	 * @return array<string,array<string,mixed>> Grant map.
	 */
	private function verification_read_grant_map( array $preflight ): array {
		$grants  = array();
		$granted = is_array( $preflight['execution_verification_reads']['granted'] ?? null ) ? (array) $preflight['execution_verification_reads']['granted'] : array();

		foreach ( $granted as $grant ) {
			if ( ! is_array( $grant ) || empty( $grant['request_id'] ) || empty( $grant['ability_id'] ) ) {
				continue;
			}
			$signature = $this->verification_read_signature( is_array( $grant['input'] ?? null ) ? $grant['input'] : array() );
			if ( '' === $signature ) {
				continue;
			}
			$grants[ (string) $grant['ability_id'] . '|' . $signature ] = array(
				'request_id' => sanitize_text_field( (string) $grant['request_id'] ),
				'input'      => is_array( $grant['input'] ?? null ) ? $grant['input'] : array(),
			);
		}

		return $grants;
	}

	/**
	 * Returns the object signature of one verification read input.
	 *
	 * @param array<string,mixed> $input Verification read input.
	 * @return string Signature like "post:42" or "slug:single", empty when unaddressed.
	 */
	private function verification_read_signature( array $input ): string {
		if ( isset( $input['post_id'] ) && is_numeric( $input['post_id'] ) && (int) $input['post_id'] > 0 ) {
			return 'post:' . (int) $input['post_id'];
		}
		if ( isset( $input['slug'] ) && is_string( $input['slug'] ) && '' !== (string) $input['slug'] ) {
			return 'slug:' . sanitize_key( (string) $input['slug'] );
		}

		return '';
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
	 * Returns a REST response for a terminal list-proxy result, relaying a
	 * Core 429 backoff hint as a standard Retry-After header.
	 *
	 * Only call this from handlers that return directly to the REST server;
	 * internal flows must keep branching on is_wp_error().
	 *
	 * @param WP_REST_Response|WP_Error $result Upstream result.
	 * @return WP_REST_Response|WP_Error
	 */
	private function relay_list_proxy_result( $result ) {
		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			if ( is_array( $error_data ) && absint( $error_data['retry_after'] ?? 0 ) > 0 ) {
				return $this->rest_response_with_retry_after( $result );
			}
		}

		return $result;
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
