<?php
/**
 * Executes one normalized Adapter action.
 *
 * @package NpcinkOpenClawAdapter
 */

namespace Npcink\OpenClawAdapter\Rest;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs an already-governed action through the injected Adapter seams.
 */
final class Execution_Action_Runner {
	/**
	 * Canonical execution profiles.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $profiles;

	/**
	 * Controlled upstream dispatch seam.
	 *
	 * @var callable
	 */
	private $dispatch;

	/**
	 * Controlled block-write readback seam.
	 *
	 * @var callable
	 */
	private $readback;

	/**
	 * Creates the runner from canonical profiles and controlled I/O seams.
	 *
	 * @param array<string,array<string,mixed>> $profiles Canonical profiles.
	 * @param callable                          $dispatch Controlled dispatch callable.
	 * @param callable                          $readback Controlled readback callable.
	 */
	public function __construct( array $profiles, callable $dispatch, callable $readback ) {
		$this->profiles = $profiles;
		$this->dispatch = $dispatch;
		$this->readback = $readback;
	}

	/**
	 * Returns the Adapter execution profile id for one final write ability.
	 *
	 * @param string $ability_id Ability id.
	 * @return string
	 */
	public function profile_id( string $ability_id ): string {
		return sanitize_text_field( $ability_id );
	}

	/**
	 * Returns or derives a bounded per-action execution idempotency key.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param string              $action_id Action id.
	 * @param array<string,mixed> $input Action input.
	 * @return string
	 */
	public function idempotency_key( string $proposal_id, string $action_id, array $input ): string {
		$provided = sanitize_text_field( (string) ( $input['idempotency_key'] ?? '' ) );
		if ( '' !== $provided ) {
			return $provided;
		}

		return 'adapter-' . substr( hash( 'sha256', $proposal_id . '|' . $action_id ), 0, 24 );
	}

	/**
	 * Executes one normalized action through the injected Abilities API seam.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param array<string,mixed> $action Normalized action.
	 * @param array<string,mixed> $approval_context Core approval context.
	 * @param string              $correlation_id Correlation id.
	 * @param array<string,mixed> $base_request_context Base request context.
	 * @return array<string,mixed>|WP_Error
	 */
	public function execute( string $proposal_id, array $action, array $approval_context, string $correlation_id, array $base_request_context ) {
		$ability_id = sanitize_text_field( (string) ( $action['ability_id'] ?? '' ) );
		$post_id    = absint( $action['post_id'] ?? 0 );
		$profile    = is_array( $this->profiles[ $ability_id ] ?? null ) ? $this->profiles[ $ability_id ] : array();

		$post_status_before = get_post_status( $post_id );
		$post_status_before = false === $post_status_before ? '' : (string) $post_status_before;

		$ability_input   = is_array( $action['input'] ?? null ) ? $action['input'] : array();
		$idempotency_key = sanitize_text_field( (string) ( $action['idempotency_key'] ?? '' ) );
		if ( '' === $idempotency_key ) {
			$idempotency_key = $this->idempotency_key( $proposal_id, sanitize_key( (string) ( $action['action_id'] ?? '' ) ), $ability_input );
		}
		if ( ! empty( $profile['force_post_input'] ) ) {
			$ability_input = array(
				'post_id' => $post_id,
				'dry_run' => false,
				'commit'  => true,
			);
		} else {
			$ability_input['dry_run'] = false;
			$ability_input['commit']  = true;
		}
		if ( ! isset( $ability_input['idempotency_key'] ) ) {
			$ability_input['idempotency_key'] = $idempotency_key;
		}

		$route           = '/wp-abilities/v1/abilities/' . $ability_id . '/run';
		$request_context = $base_request_context;
		$request_context['ability_id'] = $ability_id;
		$context         = array_merge(
			$approval_context,
			$request_context,
			array(
				'ability_id'        => $ability_id,
				'target_ability_id' => sanitize_text_field( (string) ( $action['target_ability_id'] ?? $ability_id ) ),
				'execution_profile' => sanitize_text_field( (string) ( $action['execution_profile'] ?? $this->profile_id( $ability_id ) ) ),
				'idempotency_key'   => $idempotency_key,
				'action_id'         => sanitize_key( (string) ( $action['action_id'] ?? '' ) ),
				'action_index'      => absint( $action['action_index'] ?? 0 ),
				'proposal_id'       => $proposal_id,
				'post_id'           => $post_id,
				'correlation_id'    => $correlation_id,
				'via'               => 'npcink-ai-client-adapter',
			)
		);

		$response = call_user_func( $this->dispatch, $context, 'POST', $route, array( 'input' => $ability_input ), false, true );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$result_data = $response->get_data();
		if ( ! empty( $profile['post_id_from_result'] ) && is_array( $result_data ) ) {
			$post_id = absint( $result_data['post_id'] ?? $post_id );
		}
		if ( is_array( $result_data ) ) {
			$readback_verification = call_user_func( $this->readback, $ability_id, $ability_input, $result_data, $base_request_context );
			if ( ! empty( $readback_verification ) ) {
				$result_data['verification'] = array_merge(
					is_array( $result_data['verification'] ?? null ) ? $result_data['verification'] : array(),
					$readback_verification
				);
			}
		}

		$post_status_after = get_post_status( $post_id );

		$result = array(
			'action_id'          => sanitize_key( (string) ( $action['action_id'] ?? '' ) ),
			'action_index'       => absint( $action['action_index'] ?? 0 ),
			'target_ability_id'  => sanitize_text_field( (string) ( $action['target_ability_id'] ?? $ability_id ) ),
			'ability_id'         => $ability_id,
			'execution_profile'  => sanitize_text_field( (string) ( $action['execution_profile'] ?? $this->profile_id( $ability_id ) ) ),
			'idempotency_key'    => $idempotency_key,
			'post_id'            => $post_id,
			'status'             => 'executed',
			'post_status_before' => $post_status_before,
			'post_status_after'  => false === $post_status_after ? '' : (string) $post_status_after,
			'adapter_request_id' => (string) ( $context['adapter_request_id'] ?? '' ),
			'result'             => $result_data,
		);
		if ( is_array( $action['media_alt_live_preflight'] ?? null ) ) {
			$result['media_alt_live_preflight'] = $action['media_alt_live_preflight'];
		}

		return $result;
	}
}
