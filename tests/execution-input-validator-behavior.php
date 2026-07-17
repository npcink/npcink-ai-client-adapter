<?php
/**
 * Executable behavior tests for Adapter execution input validation.
 *
 * @package NpcinkOpenClawAdapter
 */

define( 'ABSPATH', __DIR__ . '/' );

if ( ! class_exists( 'WP_Error' ) ) {
	final class WP_Error {
		/** @var string */
		private $code;
		/** @var string */
		private $message;
		/** @var mixed */
		private $data;

		public function __construct( string $code, string $message, $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function __( string $text ): string {
	return $text;
}

function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}

function sanitize_key( string $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?? '' );
}

function sanitize_title( string $value ): string {
	$value = strtolower( trim( $value ) );
	return trim( preg_replace( '/[^a-z0-9]+/', '-', $value ) ?? '', '-' );
}

function absint( $value ): int {
	return abs( (int) $value );
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function wp_strip_all_tags( string $value ): string {
	return strip_tags( $value );
}

function taxonomy_exists( string $taxonomy ): bool {
	return in_array( $taxonomy, array( 'category', 'post_tag' ), true );
}

/**
 * @param bool   $condition Assertion.
 * @param string $message Failure message.
 */
function npcink_adapter_validator_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, '[fail] ' . $message . "\n" );
		exit( 1 );
	}
}

require_once dirname( __DIR__ ) . '/includes/Rest/Execution_Input_Validator.php';

use Npcink\OpenClawAdapter\Rest\Execution_Input_Validator;

$profiles = array(
	'npcink-abilities-toolkit/test-update' => array(
		'supported_input_fields' => array( 'post_id', 'mode', 'dry_run', 'commit' ),
		'require_post_id'        => array(
			'code'    => 'test_post_id_required',
			'message' => 'post_id required',
		),
		'enum_fields'           => array(
			'mode' => array(
				'allowed' => array( 'replace' ),
				'code'    => 'test_mode_invalid',
				'message' => 'mode invalid',
			),
		),
	),
	'npcink-abilities-toolkit/test-setting' => array(
		'supported_input_fields' => array( 'target_type', 'target_name', 'operations' ),
		'site_readiness'         => array(
			'filter'            => 'npcink_abilities_toolkit_patchable_setting_targets',
			'policy_owner'      => 'wordpress_host',
			'target_type_field' => 'target_type',
			'target_name_field' => 'target_name',
			'not_ready_code'    => 'test_setting_not_ready',
		),
	),
);

$validator = new Execution_Input_Validator( $profiles );

$unsupported = $validator->validate_execute_action_input(
	'proposal-1',
	'npcink-abilities-toolkit/test-update',
	array( 'post_id' => 12, 'mode' => 'replace', 'hidden' => true ),
	12
);
npcink_adapter_validator_assert( is_wp_error( $unsupported ), 'unsupported input fields fail closed' );
npcink_adapter_validator_assert( 'npcink_openclaw_adapter_ability_input_field_unsupported' === $unsupported->get_error_code(), 'unsupported input field keeps stable error code' );
npcink_adapter_validator_assert( 'hidden' === ( $unsupported->get_error_data()['field'] ?? '' ), 'unsupported input field is identified' );

$invalid_enum = $validator->validate_execute_action_input(
	'proposal-2',
	'npcink-abilities-toolkit/test-update',
	array( 'post_id' => 12, 'mode' => 'append' ),
	12
);
npcink_adapter_validator_assert( is_wp_error( $invalid_enum ), 'invalid enum values fail closed' );
npcink_adapter_validator_assert( 'test_mode_invalid' === $invalid_enum->get_error_code(), 'profile enum error code is preserved' );

$valid = $validator->validate_execute_action_input(
	'proposal-3',
	'npcink-abilities-toolkit/test-update',
	array( 'post_id' => 12, 'mode' => 'replace' ),
	12
);
npcink_adapter_validator_assert( true === $valid, 'valid profiled input succeeds' );

$prior_reference = array( 'post_id' => '$outputs.create-draft.post_id' );
npcink_adapter_validator_assert(
	true === $validator->validate_output_references( 'proposal-4', $prior_reference, array( 'create-draft' => true ), 1 ),
	'output references may target an earlier action'
);

$future_reference = $validator->validate_output_references( 'proposal-4', $prior_reference, array(), 0 );
npcink_adapter_validator_assert( is_wp_error( $future_reference ), 'output references cannot target unavailable actions' );
npcink_adapter_validator_assert( 'npcink_openclaw_adapter_output_reference_unavailable' === $future_reference->get_error_code(), 'unavailable output keeps stable error code' );

$malformed_reference = $validator->validate_output_references(
	'proposal-4',
	array( 'post_id' => 'prefix-$outputs.create-draft.post_id' ),
	array( 'create-draft' => true ),
	1
);
npcink_adapter_validator_assert( is_wp_error( $malformed_reference ), 'embedded output references fail closed' );
npcink_adapter_validator_assert( 'npcink_openclaw_adapter_output_reference_invalid' === $malformed_reference->get_error_code(), 'malformed output keeps stable error code' );

$resolved = $validator->resolve_output_references(
	array( 'post_id' => '$outputs.create-draft.post_id', 'title' => 'Reviewed' ),
	array( 'create-draft' => array( 'post_id' => 42 ) ),
	'proposal-4',
	1
);
npcink_adapter_validator_assert( is_array( $resolved ) && 42 === ( $resolved['post_id'] ?? 0 ), 'output reference resolves exact prior output value' );

$site_not_ready = $validator->validate_execute_action_input(
	'proposal-5',
	'npcink-abilities-toolkit/test-setting',
	array(
		'target_type' => 'option',
		'target_name' => 'reviewed_target',
		'operations'  => array( array( 'op' => 'replace', 'value' => true ) ),
	),
	0,
	null,
	false,
	true
);
npcink_adapter_validator_assert( is_wp_error( $site_not_ready ), 'missing host allowlist fails closed' );
npcink_adapter_validator_assert( 'test_setting_not_ready' === $site_not_ready->get_error_code(), 'site readiness keeps profile error code' );
npcink_adapter_validator_assert( false === ( $site_not_ready->get_error_data()['target_name_exposed'] ?? true ), 'site readiness does not expose target name' );

fwrite( STDOUT, "Execution input validator behavior: ok\n" );
