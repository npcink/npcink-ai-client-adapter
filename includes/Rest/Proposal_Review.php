<?php
/**
 * Proposal review loop domain service: input gates and operator feedback.
 *
 * Owns proposal/plan input validation against the execution profile rules
 * and every operator-feedback builder that turns Core rejections, blocked
 * preflight items, and plan handoff failures into bounded, actionable
 * `data.operator_feedback` payloads. Proposal truth stays in Core.
 *
 * @package NpcinkOpenClawAdapter
 */

namespace Npcink\OpenClawAdapter\Rest;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates proposal inputs and builds operator feedback.
 */
final class Proposal_Review {

	/**
	 * Execution input validator for profile-level input rules.
	 *
	 * @var Execution_Input_Validator
	 */
	private $input_validator;

	/**
	 * Creates the proposal review service.
	 *
	 * @param Execution_Input_Validator $input_validator Execution input validator.
	 */
	public function __construct( Execution_Input_Validator $input_validator ) {
		$this->input_validator = $input_validator;
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
	public function validate_create_input( string $ability_id, array $input, bool $allow_output_refs = false, ?int $action_index = null ) {
		$ability_id = sanitize_text_field( $ability_id );
		$profiles   = Execution_Profile_Registry::profiles();
		if ( ! isset( $profiles[ $ability_id ] ) ) {
			return true;
		}

		return $this->input_validator->validate_execute_action_input( 'proposal_create', $ability_id, $input, absint( $input['post_id'] ?? 0 ), $action_index, $allow_output_refs );
	}
	/**
	 * Makes dependent/output-reference plan batches explicit for Core versions that do not infer it.
	 *
	 * @param array<string,mixed> $plan_payload Plan output or success envelope.
	 * @return array<string,mixed>
	 */
	public function normalize_batch_metadata( array $plan_payload ): array {
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
			if ( ! empty( $depends_on ) || ! empty( $this->input_validator->collect_output_references( $action['input'] ?? array() ) ) ) {
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
	public function validate_plan_write_actions( array $plan_payload ) {
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
			$valid_refs  = $this->input_validator->validate_output_references( 'proposal_create', $input, $available_outputs, $index );
			$valid_input = is_wp_error( $valid_refs ) ? $valid_refs : $this->validate_create_input( $target_ability_id, $input, true, $index );
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
	 * Returns a WP_Error data array.
	 *
	 * @param WP_Error $error Error.
	 * @return array<string,mixed>
	 */
	public function error_data( WP_Error $error ): array {
		$data = $error->get_error_data();
		return is_array( $data ) ? $data : array();
	}
	/**
	 * Extracts the upstream error detail payload from Adapter wrapped errors.
	 *
	 * @param WP_Error $error Error.
	 * @return array<string,mixed>
	 */
	public function upstream_error( WP_Error $error ): array {
		$data     = $this->error_data( $error );
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
	 * Returns rejection reasons from Core audit timeline.
	 *
	 * @param array<string,mixed> $proposal Core proposal.
	 * @return array<int,string>
	 */
	public function core_rejection_reasons( array $proposal ): array {
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
	public function reasons_from_blocked_items( array $blocked_items ): array {
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
	public function revision_fields( array $blocked_items, array $core_data ): array {
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
	 * Returns the Core batch review summary from a proposal preview.
	 *
	 * @param array<string,mixed> $proposal Core proposal.
	 * @return array<string,mixed>
	 */
	public function batch_review_summary( array $proposal ): array {
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
	public function feedback_from_summary( array $summary, array $proposal = array() ): array {
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
	 * Builds batch review feedback for a Core from-plan response.
	 *
	 * @param array<string,mixed> $data Core response.
	 * @return array<string,mixed>
	 */
	public function feedback_from_proposals( array $data ): array {
		$proposals = is_array( $data['proposals'] ?? null ) ? array_values( $data['proposals'] ) : array();
		$items     = array();

		foreach ( $proposals as $proposal ) {
			if ( ! is_array( $proposal ) ) {
				continue;
			}
			$feedback = $this->feedback_from_summary( $this->batch_review_summary( $proposal ), $proposal );
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
	public function feedback_from_preflight( array $preflight, array $proposal = array() ): array {
		$item_preflight = is_array( $preflight['proposal_item_preflight'] ?? null ) ? $preflight['proposal_item_preflight'] : array();
		$summary        = is_array( $item_preflight['batch_review_summary'] ?? null ) ? $item_preflight['batch_review_summary'] : array();
		if ( empty( $summary ) ) {
			$summary = $this->batch_review_summary( $proposal );
		}

		return $this->feedback_from_summary( $summary, $proposal );
	}
	/**
	 * Builds operator feedback for plan handoff failures.
	 *
	 * @param WP_Error $error Error.
	 * @param string   $plan_ability_id Planning ability id.
	 * @return array<string,mixed>
	 */
	public function plan_handoff_feedback( WP_Error $error, string $plan_ability_id ): array {
		$error_data = $this->error_data( $error );
		$core_data  = $this->upstream_error( $error );
		$blocked    = is_array( $error_data['blocked_items'] ?? null ) ? $error_data['blocked_items'] : array();
		if ( empty( $blocked ) && is_array( $core_data['blocked_items'] ?? null ) ) {
			$blocked = $core_data['blocked_items'];
		}

		$reasons = $this->reasons_from_blocked_items( $blocked );
		if ( empty( $reasons ) ) {
			$reasons[] = $error->get_error_message();
		}

		return array(
			'status'                   => 'plan_revision_required',
			'severity'                 => 'error',
			'message'                  => __( 'The plan was not accepted for Core proposal intake.', 'npcink-ai-client-adapter' ),
			'reasons'                  => $reasons,
			'revision_fields'          => $this->revision_fields( $blocked, $core_data ),
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
	public function proposal_status_feedback( array $proposal, string $status_before ): array {
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
	public function preflight_feedback( ?WP_Error $error, array $proposal, array $preflight = array() ): array {
		if ( empty( $preflight ) && null !== $error ) {
			$preflight = $this->upstream_error( $error );
		}

		$item_preflight        = is_array( $preflight['proposal_item_preflight'] ?? null ) ? $preflight['proposal_item_preflight'] : array();
		$blocked               = is_array( $item_preflight['blocked_items'] ?? null ) ? $item_preflight['blocked_items'] : array();
		$needs_input           = array_values( array_map( 'sanitize_key', (array) ( $item_preflight['needs_input'] ?? array() ) ) );
		$batch_review_feedback = $this->feedback_from_preflight( $preflight, $proposal );
		$reasons               = $this->reasons_from_blocked_items( $blocked );

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
}
