<?php
/**
 * Adapter execution input validation and output reference handling.
 *
 * @package NpcinkOpenClawAdapter
 */

namespace Npcink\OpenClawAdapter\Rest;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates and resolves Adapter-owned execution profile inputs.
 *
 * This service owns input shape checks only. It does not approve proposals,
 * dispatch abilities, persist governance state, or execute WordPress writes.
 */
final class Execution_Input_Validator {
	private const MAX_ACTION_INPUT_BYTES = 1048576;
	private const MAX_BLOCK_ITEMS        = 300;
	private const MAX_OPERATION_ITEMS    = 300;
	private const MAX_TERM_ITEMS         = 100;

	/**
	 * Literal Adapter execution profiles.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $profiles;

	/**
	 * @param array<string,array<string,mixed>> $profiles Adapter-owned profiles.
	 */
	public function __construct( array $profiles ) {
		$this->profiles = $profiles;
	}

	/**
	 * @param string $proposal_id Proposal id.
	 * @param string $ability_id Ability id.
	 * @return true|WP_Error
	 */
	public function validate_execute_ability( string $proposal_id, string $ability_id ) {
		$profiles = $this->profiles;
		if ( isset( $profiles[ $ability_id ] ) ) {
			return true;
		}

			return new WP_Error(
				'npcink_openclaw_adapter_execute_profile_unsupported',
				__( 'This proposal ability is not implemented by Adapter execution profiles.', 'npcink-ai-client-adapter' ),
			array(
				'status'                      => 403,
				'proposal_id'                 => $proposal_id,
				'ability_id'                  => $ability_id,
				'supported_execute_ability_ids' => array_keys( $this->profiles ),
			)
		);
	}

	/**
	 * Bounds Adapter-owned execution input before validation or dispatch.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param string              $ability_id Ability id.
	 * @param array<string,mixed> $input Ability input.
	 * @param int|null            $action_index Batch action index.
	 * @return true|WP_Error
	 */
	private function validate_execute_action_input_size( string $proposal_id, string $ability_id, array $input, ?int $action_index = null ) {
		$error_data = array(
			'status'      => 413,
			'proposal_id' => $proposal_id,
			'ability_id'  => $ability_id,
		);
		if ( null !== $action_index ) {
			$error_data['action_index']      = $action_index;
			$error_data['target_ability_id'] = $ability_id;
		}

		$json  = wp_json_encode( $input );
		$bytes = is_string( $json ) ? strlen( $json ) : 0;
		if ( $bytes > self::MAX_ACTION_INPUT_BYTES ) {
			return new WP_Error(
				'npcink_openclaw_adapter_action_input_too_large',
				__( 'Execution input exceeds the adapter action payload limit.', 'npcink-ai-client-adapter' ),
				array_merge(
					$error_data,
					array(
						'input_bytes' => $bytes,
						'max_bytes'   => self::MAX_ACTION_INPUT_BYTES,
					)
				)
			);
		}

		foreach (
			array(
				'blocks'     => self::MAX_BLOCK_ITEMS,
				'operations' => self::MAX_OPERATION_ITEMS,
				'term_ids'   => self::MAX_TERM_ITEMS,
				'terms'      => self::MAX_TERM_ITEMS,
			) as $field => $max_items
		) {
			if ( ! is_array( $input[ $field ] ?? null ) || count( $input[ $field ] ) <= $max_items ) {
				continue;
			}

			return new WP_Error(
				'npcink_openclaw_adapter_action_items_limit_exceeded',
				__( 'Execution input includes too many items for one field.', 'npcink-ai-client-adapter' ),
				array_merge(
					$error_data,
					array(
						'field'      => $field,
						'item_count' => count( $input[ $field ] ),
						'max_items'  => $max_items,
					)
				)
			);
		}

		return true;
	}

	/**
	 * Validates the Adapter-owned execution input shape for one supported ability.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param string              $ability_id Ability id.
	 * @param array<string,mixed> $input Ability input.
	 * @param int                 $post_id Post id when the ability targets an existing post.
	 * @param int|null            $action_index Batch action index.
	 * @param bool                $allow_output_refs Whether batch output references may satisfy id fields.
	 * @param bool                $enforce_site_readiness Whether host policy must allow final execution.
	 * @return true|WP_Error
	 */
	public function validate_execute_action_input( string $proposal_id, string $ability_id, array $input, int $post_id, ?int $action_index = null, bool $allow_output_refs = false, bool $enforce_site_readiness = false ) {
		$error_data = array(
			'status'      => 400,
			'proposal_id' => $proposal_id,
			'ability_id'  => $ability_id,
		);
		if ( null !== $action_index ) {
			$error_data['action_index']      = $action_index;
			$error_data['target_ability_id'] = $ability_id;
		}

		$profiles = $this->profiles;
		$profile  = is_array( $profiles[ $ability_id ] ?? null ) ? $profiles[ $ability_id ] : array();

		$bounded_input = $this->validate_execute_action_input_size( $proposal_id, $ability_id, $input, $action_index );
		if ( is_wp_error( $bounded_input ) ) {
			return $bounded_input;
		}

		$supported_input_fields = (array) ( $profile['supported_input_fields'] ?? array() );
		if ( ! empty( $supported_input_fields ) ) {
			foreach ( array_keys( $input ) as $field ) {
				$field = (string) $field;
				if ( in_array( $field, $supported_input_fields, true ) ) {
					continue;
				}

					return new WP_Error(
						'npcink_openclaw_adapter_ability_input_field_unsupported',
						__( 'Proposal input includes a field outside this ability schema.', 'npcink-ai-client-adapter' ),
					array_merge(
						$error_data,
						array(
							'field'                => $field,
							'supported_input_fields' => $supported_input_fields,
						)
					)
				);
			}
		}

		$post_id_rule = is_array( $profile['require_post_id'] ?? null ) ? $profile['require_post_id'] : array();
		if ( ! empty( $post_id_rule ) && 0 === $post_id ) {
			if ( $allow_output_refs && $this->is_output_reference( $input['post_id'] ?? null ) ) {
				$post_id_rule = array();
			}
		}
		if ( ! empty( $post_id_rule ) && 0 === $post_id ) {
			return new WP_Error(
				(string) ( $post_id_rule['code'] ?? 'npcink_openclaw_adapter_post_id_required' ),
				(string) ( $post_id_rule['message'] ?? __( 'Execution input must include post_id.', 'npcink-ai-client-adapter' ) ),
				$error_data
			);
		}

		foreach ( (array) ( $profile['required_text_fields'] ?? array() ) as $field => $rule ) {
			$rule = is_array( $rule ) ? $rule : array();
			if ( '' !== trim( sanitize_text_field( (string) ( $input[ $field ] ?? '' ) ) ) ) {
				continue;
			}

			return new WP_Error(
				(string) ( $rule['code'] ?? 'npcink_openclaw_adapter_required_text_missing' ),
				(string) ( $rule['message'] ?? __( 'Execution input is missing a required text field.', 'npcink-ai-client-adapter' ) ),
				$error_data
			);
		}

		foreach ( (array) ( $profile['required_slug_fields'] ?? array() ) as $field => $rule ) {
			$rule = is_array( $rule ) ? $rule : array();
			if ( '' !== sanitize_title( (string) ( $input[ $field ] ?? '' ) ) ) {
				continue;
			}

			return new WP_Error(
				(string) ( $rule['code'] ?? 'npcink_openclaw_adapter_required_slug_missing' ),
				(string) ( $rule['message'] ?? __( 'Execution input is missing a required slug field.', 'npcink-ai-client-adapter' ) ),
				$error_data
			);
		}

		foreach ( (array) ( $profile['enum_fields'] ?? array() ) as $field => $rule ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$rule    = is_array( $rule ) ? $rule : array();
			$value   = sanitize_key( (string) $input[ $field ] );
			$allowed = (array) ( $rule['allowed'] ?? array() );
			if ( in_array( $value, $allowed, true ) ) {
				continue;
			}

			return new WP_Error(
				(string) ( $rule['code'] ?? 'npcink_openclaw_adapter_input_enum_invalid' ),
				(string) ( $rule['message'] ?? __( 'Proposal input includes an invalid enum value.', 'npcink-ai-client-adapter' ) ),
				array_merge(
					$error_data,
					array(
						'field'          => (string) $field,
						'value'          => $value,
						'allowed_values' => $allowed,
					),
				)
			);
		}

		$any_fields_rule = is_array( $profile['require_any_fields'] ?? null ) ? $profile['require_any_fields'] : array();
		if ( ! empty( $any_fields_rule ) ) {
			$has_any_field = false;
			foreach ( (array) ( $any_fields_rule['fields'] ?? array() ) as $field ) {
				if ( array_key_exists( $field, $input ) ) {
					$has_any_field = true;
					break;
				}
			}
			if ( ! $has_any_field ) {
				return new WP_Error(
					(string) ( $any_fields_rule['code'] ?? 'npcink_openclaw_adapter_required_fields_missing' ),
					(string) ( $any_fields_rule['message'] ?? __( 'Execution input is missing required fields.', 'npcink-ai-client-adapter' ) ),
					$error_data
				);
			}
		}

		foreach ( (array) ( $profile['require_array_fields'] ?? array() ) as $field => $rule ) {
			$rule  = is_array( $rule ) ? $rule : array();
			$value = $input[ $field ] ?? null;
			if ( is_array( $value ) && ! empty( $value ) ) {
				continue;
			}

			return new WP_Error(
				(string) ( $rule['code'] ?? 'npcink_openclaw_adapter_required_array_missing' ),
				(string) ( $rule['message'] ?? __( 'Execution input is missing a required array field.', 'npcink-ai-client-adapter' ) ),
				array_merge(
					$error_data,
					array(
						'field' => (string) $field,
					)
				)
			);
		}

		foreach ( (array) ( $profile['required_int_fields'] ?? array() ) as $field => $rule ) {
			$rule = is_array( $rule ) ? $rule : array();
			if ( 0 < absint( $input[ $field ] ?? 0 ) ) {
				continue;
			}
			if ( $allow_output_refs && $this->is_output_reference( $input[ $field ] ?? null ) ) {
				continue;
			}

			return new WP_Error(
				(string) ( $rule['code'] ?? 'npcink_openclaw_adapter_required_int_missing' ),
				(string) ( $rule['message'] ?? __( 'Execution input is missing a required id.', 'npcink-ai-client-adapter' ) ),
				$error_data
			);
		}

		if ( ! empty( $profile['validate_attachment_input'] ) ) {
			$attachment_id = absint( $input['attachment_id'] ?? 0 );
			$defer_attachment_check = $allow_output_refs && $this->is_output_reference( $input['attachment_id'] ?? null );
			if ( ! $defer_attachment_check && function_exists( 'get_post_type' ) && 'attachment' !== get_post_type( $attachment_id ) ) {
				$attachment_rule = is_array( $profile['validate_attachment_input'] ) ? $profile['validate_attachment_input'] : array();
				return new WP_Error(
					'npcink_openclaw_adapter_attachment_required',
					(string) ( $attachment_rule['message'] ?? __( 'Execution input must target an existing attachment.', 'npcink-ai-client-adapter' ) ),
					array_merge(
						$error_data,
						array(
							'attachment_id' => $attachment_id,
						)
					)
				);
			}
		}

		if ( ! empty( $profile['validate_terms_input'] ) ) {
			$taxonomy = sanitize_key( (string) ( $input['taxonomy'] ?? 'post_tag' ) );
			if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
				return new WP_Error(
					'npcink_openclaw_adapter_taxonomy_required',
					__( 'set-post-terms execution input must include a valid taxonomy.', 'npcink-ai-client-adapter' ),
					$error_data
				);
			}

			$mode = sanitize_key( (string) ( $input['mode'] ?? 'replace' ) );
			if ( ! in_array( $mode, array( 'replace', 'append', 'remove' ), true ) ) {
				return new WP_Error(
					'npcink_openclaw_adapter_term_mode_invalid',
					__( 'set-post-terms execution mode must be replace, append, or remove.', 'npcink-ai-client-adapter' ),
					$error_data
				);
			}

			$term_ids = is_array( $input['term_ids'] ?? null ) ? array_filter( array_map( 'absint', $input['term_ids'] ) ) : array();
			$terms    = is_array( $input['terms'] ?? null ) ? array_filter(
				array_map(
					static function ( $term ) {
						return trim( sanitize_text_field( (string) $term ) );
					},
					$input['terms']
				)
			) : array();
			if ( empty( $term_ids ) && empty( $terms ) ) {
				return new WP_Error(
					'npcink_openclaw_adapter_terms_required',
					__( 'set-post-terms execution input must include term_ids or terms.', 'npcink-ai-client-adapter' ),
					$error_data
				);
			}

				if ( ! empty( $input['create_missing'] ) ) {
					return new WP_Error(
						'npcink_openclaw_adapter_create_missing_terms_unsupported',
						__( 'set-post-terms execution does not implement creating missing terms.', 'npcink-ai-client-adapter' ),
					$error_data
				);
			}
		}

		if ( ! empty( $profile['validate_delete_term_input'] ) ) {
			$taxonomy = array_key_exists( 'taxonomy', $input ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
			if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
				return new WP_Error(
					'npcink_openclaw_adapter_taxonomy_required',
					__( 'delete-term execution input must include a valid taxonomy.', 'npcink-ai-client-adapter' ),
					$error_data
				);
			}
		}

		$comment_body_rule = is_array( $profile['require_comment_body'] ?? null ) ? $profile['require_comment_body'] : array();
		if ( ! empty( $comment_body_rule ) ) {
			$content = (string) ( $input['content'] ?? '' );
			if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
				return new WP_Error(
					(string) ( $comment_body_rule['code'] ?? 'npcink_openclaw_adapter_comment_content_required' ),
					(string) ( $comment_body_rule['message'] ?? __( 'Comment execution input must include content.', 'npcink-ai-client-adapter' ) ),
					$error_data
				);
			}
		}

		$content_format_rule = is_array( $profile['content_formats'] ?? null ) ? $profile['content_formats'] : array();
		if ( ! empty( $content_format_rule ) ) {
			$content_format = sanitize_key( (string) ( $input['content_format'] ?? 'html' ) );
			if ( ! in_array( $content_format, (array) ( $content_format_rule['allowed'] ?? array() ), true ) ) {
				return new WP_Error(
					(string) ( $content_format_rule['code'] ?? 'npcink_openclaw_adapter_content_format_invalid' ),
					(string) ( $content_format_rule['message'] ?? __( 'Comment content_format is invalid.', 'npcink-ai-client-adapter' ) ),
					$error_data
				);
			}
		}

		if ( $enforce_site_readiness ) {
			$site_ready = $this->validate_execution_profile_site_readiness( $proposal_id, $ability_id, $input, $action_index );
			if ( is_wp_error( $site_ready ) ) {
				return $site_ready;
			}
		}

		return true;
	}

	/**
	 * Validates site-owned conditions for one final execution profile.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param string              $ability_id Ability id.
	 * @param array<string,mixed> $input Ability input.
	 * @param int|null            $action_index Batch action index.
	 * @return true|WP_Error
	 */
	private function validate_execution_profile_site_readiness( string $proposal_id, string $ability_id, array $input, ?int $action_index = null ) {
		$profiles = $this->profiles;
		$profile  = is_array( $profiles[ $ability_id ] ?? null ) ? $profiles[ $ability_id ] : array();
		$policy   = is_array( $profile['site_readiness'] ?? null ) ? $profile['site_readiness'] : array();
		if ( empty( $policy ) ) {
			return true;
		}

		$filter            = sanitize_key( (string) ( $policy['filter'] ?? '' ) );
		$target_type_field = sanitize_key( (string) ( $policy['target_type_field'] ?? 'target_type' ) );
		$target_name_field = sanitize_key( (string) ( $policy['target_name_field'] ?? 'target_name' ) );
		$target_type       = sanitize_key( (string) ( $input[ $target_type_field ] ?? '' ) );
		$target_name       = sanitize_key( (string) ( $input[ $target_name_field ] ?? '' ) );
		$allowed_targets   = array(
			'option'    => array(),
			'theme_mod' => array(),
		);
		if ( 'npcink_abilities_toolkit_patchable_setting_targets' === $filter && function_exists( 'apply_filters' ) ) {
			$allowed_targets = apply_filters( 'npcink_abilities_toolkit_patchable_setting_targets', $allowed_targets, $target_type, $target_name );
		}
		$allowed_targets = is_array( $allowed_targets ) ? $allowed_targets : array();
		$allowed_names   = isset( $allowed_targets[ $target_type ] ) && is_array( $allowed_targets[ $target_type ] )
			? array_filter( array_map( 'sanitize_key', $allowed_targets[ $target_type ] ) )
			: array();
		if ( '' !== $target_name && in_array( $target_name, $allowed_names, true ) ) {
			return true;
		}

		$error_data = array(
			'status'                    => 409,
			'proposal_id'               => $proposal_id,
			'ability_id'                => $ability_id,
			'target_type'               => $target_type,
			'site_readiness_status'     => 'not_ready',
			'site_policy_owner'         => sanitize_key( (string) ( $policy['policy_owner'] ?? 'wordpress_host' ) ),
			'site_policy_filter'        => $filter,
			'per_target_check_required' => true,
			'target_name_exposed'       => false,
			'operator_next_action'      => 'configure_reviewed_host_target_allowlist_and_create_a_new_proposal',
		);
		if ( null !== $action_index ) {
			$error_data['action_index']      = $action_index;
			$error_data['target_ability_id'] = $ability_id;
		}

		return new WP_Error(
			(string) ( $policy['not_ready_code'] ?? 'npcink_openclaw_adapter_execution_profile_site_not_ready' ),
			__( 'This execution profile is supported, but the WordPress host has not allowlisted this setting target.', 'npcink-ai-client-adapter' ),
			$error_data
		);
	}

	/**
	 * Checks whether a value is an exact batch output reference.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function is_output_reference( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^\$outputs\.[A-Za-z0-9_-]+\.[A-Za-z0-9_]+$/', $value );
	}

	/**
	 * Collects exact batch output references from a value tree.
	 *
	 * @param mixed $value Value.
	 * @return array<int,string>
	 */
	public function collect_output_references( $value ): array {
		if ( $this->is_output_reference( $value ) ) {
			return array( (string) $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$references = array();
		foreach ( $value as $child ) {
			$references = array_merge( $references, $this->collect_output_references( $child ) );
		}
		return array_values( array_unique( $references ) );
	}

	/**
	 * Finds a malformed output reference token in a value tree.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function invalid_output_reference_token( $value ): string {
		if ( is_string( $value ) ) {
			if ( false !== strpos( $value, '$outputs.' ) && ! $this->is_output_reference( $value ) ) {
				return $value;
			}
			return '';
		}
		if ( ! is_array( $value ) ) {
			return '';
		}

		foreach ( $value as $child ) {
			$invalid = $this->invalid_output_reference_token( $child );
			if ( '' !== $invalid ) {
				return $invalid;
			}
		}
		return '';
	}

	/**
	 * Parses one exact batch output reference.
	 *
	 * @param string $reference Reference.
	 * @return array{action_id:string,field:string}|null
	 */
	private function parse_output_reference( string $reference ): ?array {
		if ( 1 !== preg_match( '/^\$outputs\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_]+)$/', $reference, $matches ) ) {
			return null;
		}

		return array(
			'action_id' => sanitize_key( $matches[1] ),
			'field'     => sanitize_key( $matches[2] ),
		);
	}

	/**
	 * Validates that output references only point to prior actions.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param array<string,mixed> $input Action input.
	 * @param array<string,bool>  $available_outputs Prior action ids.
	 * @param int                 $action_index Action index.
	 * @return true|WP_Error
	 */
	public function validate_output_references( string $proposal_id, array $input, array $available_outputs, int $action_index ) {
		$invalid_reference = $this->invalid_output_reference_token( $input );
		if ( '' !== $invalid_reference ) {
			return new WP_Error(
				'npcink_openclaw_adapter_output_reference_invalid',
				__( 'Batch output references must use $outputs.action_id.field as the whole value.', 'npcink-ai-client-adapter' ),
				array(
					'status'       => 400,
					'proposal_id'  => $proposal_id,
					'action_index' => $action_index,
					'reference'    => $invalid_reference,
				)
			);
		}

		foreach ( $this->collect_output_references( $input ) as $reference ) {
			$parsed = $this->parse_output_reference( $reference );
			if ( null === $parsed || empty( $available_outputs[ $parsed['action_id'] ] ) ) {
				return new WP_Error(
					'npcink_openclaw_adapter_output_reference_unavailable',
					__( 'Batch output references must point to an earlier action in the same proposal.', 'npcink-ai-client-adapter' ),
					array(
						'status'       => 400,
						'proposal_id'  => $proposal_id,
						'action_index' => $action_index,
						'reference'    => $reference,
					)
				);
			}
		}

		return true;
	}

	/**
	 * Resolves exact batch output references in an input value tree.
	 *
	 * @param mixed               $value Value.
	 * @param array<string,array<string,mixed>> $outputs Prior outputs keyed by action id.
	 * @param string              $proposal_id Proposal id.
	 * @param int                 $action_index Action index.
	 * @return mixed|WP_Error
	 */
	public function resolve_output_references( $value, array $outputs, string $proposal_id, int $action_index ) {
		if ( $this->is_output_reference( $value ) ) {
			$parsed = $this->parse_output_reference( (string) $value );
			if (
				null === $parsed
				|| ! array_key_exists( $parsed['action_id'], $outputs )
				|| ! array_key_exists( $parsed['field'], $outputs[ $parsed['action_id'] ] )
			) {
				return new WP_Error(
					'npcink_openclaw_adapter_output_reference_unresolved',
					__( 'Batch output reference could not be resolved.', 'npcink-ai-client-adapter' ),
					array(
						'status'       => 409,
						'proposal_id'  => $proposal_id,
						'action_index' => $action_index,
						'reference'    => (string) $value,
					)
				);
			}

			return $outputs[ $parsed['action_id'] ][ $parsed['field'] ];
		}

		$invalid_reference = $this->invalid_output_reference_token( $value );
		if ( '' !== $invalid_reference ) {
			return new WP_Error(
				'npcink_openclaw_adapter_output_reference_invalid',
				__( 'Batch output references must use $outputs.action_id.field as the whole value.', 'npcink-ai-client-adapter' ),
				array(
					'status'       => 400,
					'proposal_id'  => $proposal_id,
					'action_index' => $action_index,
					'reference'    => $invalid_reference,
				)
			);
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		$resolved = array();
		foreach ( $value as $key => $child ) {
			$resolved_child = $this->resolve_output_references( $child, $outputs, $proposal_id, $action_index );
			if ( is_wp_error( $resolved_child ) ) {
				return $resolved_child;
			}
			$resolved[ $key ] = $resolved_child;
		}
		return $resolved;
	}

}
