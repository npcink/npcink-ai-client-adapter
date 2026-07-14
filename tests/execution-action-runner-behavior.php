<?php
/**
 * Executable behavior tests for normalized Adapter action execution.
 *
 * @package NpcinkOpenClawAdapter
 */

define( 'ABSPATH', __DIR__ . '/' );

if ( ! class_exists( 'WP_Error' ) ) {
	final class WP_Error {
		/** @var string */
		private $code;

		public function __construct( string $code ) {
			$this->code = $code;
		}

		public function get_error_code(): string {
			return $this->code;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	final class WP_REST_Response {
		/** @var mixed */
		private $data;

		public function __construct( $data ) {
			$this->data = $data;
		}

		public function get_data() {
			return $this->data;
		}
	}
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}

function sanitize_key( string $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?? '' );
}

function absint( $value ): int {
	return abs( (int) $value );
}

/** @var array<int,string|false> */
$npcink_adapter_post_statuses = array(
	12 => 'draft',
	33 => 'pending',
	99 => 'publish',
);

function get_post_status( int $post_id ) {
	global $npcink_adapter_post_statuses;
	return $npcink_adapter_post_statuses[ $post_id ] ?? false;
}

/**
 * @param bool   $condition Assertion.
 * @param string $message Failure message.
 */
function npcink_adapter_runner_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, '[fail] ' . $message . "\n" );
		exit( 1 );
	}
}

require_once dirname( __DIR__ ) . '/includes/Rest/Execution_Action_Runner.php';

use Npcink\OpenClawAdapter\Rest\Execution_Action_Runner;

$profiles = array(
	'npcink-abilities-toolkit/update-test' => array(),
	'npcink-abilities-toolkit/create-test' => array(
		'force_post_input'   => true,
		'post_id_from_result' => true,
	),
);

$dispatch_calls = array();
$responses      = array(
	new WP_REST_Response(
		array(
			'changed'      => true,
			'verification' => array( 'upstream' => 'ok' ),
		)
	),
	new WP_REST_Response( array( 'post_id' => 99 ) ),
);
$readback_calls = array();
$runner         = new Execution_Action_Runner(
	$profiles,
	static function ( array $context, string $method, string $route, array $params, bool $query_params, bool $json_body ) use ( &$dispatch_calls, &$responses ) {
		$dispatch_calls[] = compact( 'context', 'method', 'route', 'params', 'query_params', 'json_body' );
		return array_shift( $responses );
	},
	static function ( string $ability_id, array $ability_input, array $ability_result, array $base_request_context ) use ( &$readback_calls ): array {
		$readback_calls[] = compact( 'ability_id', 'ability_input', 'ability_result', 'base_request_context' );
		return array( 'readback' => 'matched' );
	}
);

npcink_adapter_runner_assert(
	'provided-key' === $runner->idempotency_key( 'proposal-1', 'action-1', array( 'idempotency_key' => ' provided-key ' ) ),
	'provided idempotency keys are sanitized and preserved'
);
$derived_key = 'adapter-' . substr( hash( 'sha256', 'proposal-1|action-1' ), 0, 24 );
npcink_adapter_runner_assert(
	$derived_key === $runner->idempotency_key( 'proposal-1', 'action-1', array() ),
	'missing idempotency keys are derived deterministically'
);
npcink_adapter_runner_assert(
	'npcink-abilities-toolkit/update-test' === $runner->profile_id( ' npcink-abilities-toolkit/update-test ' ),
	'profile ids use the existing text sanitization contract'
);

$result = $runner->execute(
	'proposal-1',
	array(
		'action_id'         => 'update-one',
		'action_index'      => 2,
		'ability_id'        => 'npcink-abilities-toolkit/update-test',
		'target_ability_id' => 'npcink-abilities-toolkit/update-test',
		'execution_profile' => 'npcink-abilities-toolkit/update-test',
		'idempotency_key'   => 'provided-action-key',
		'post_id'           => 12,
		'input'             => array(
			'post_id' => 12,
			'title'   => 'Reviewed',
		),
		'media_alt_live_preflight' => array( 'status' => 'passed' ),
	),
	array( 'approval_status' => 'approved' ),
	'correlation-1',
	array( 'adapter_request_id' => 'request-1' )
);

$first_dispatch = $dispatch_calls[0] ?? array();
$first_input    = $first_dispatch['params']['input'] ?? array();
npcink_adapter_runner_assert( 'POST' === ( $first_dispatch['method'] ?? '' ), 'execution dispatch uses POST' );
npcink_adapter_runner_assert(
	'/wp-abilities/v1/abilities/npcink-abilities-toolkit/update-test/run' === ( $first_dispatch['route'] ?? '' ),
	'execution dispatch targets the existing Abilities API route'
);
npcink_adapter_runner_assert( false === ( $first_dispatch['query_params'] ?? null ), 'execution does not use query parameters' );
npcink_adapter_runner_assert( true === ( $first_dispatch['json_body'] ?? false ), 'execution uses a JSON body' );
npcink_adapter_runner_assert( false === ( $first_input['dry_run'] ?? null ), 'execution forces dry_run=false' );
npcink_adapter_runner_assert( true === ( $first_input['commit'] ?? false ), 'execution forces commit=true' );
npcink_adapter_runner_assert( 'provided-action-key' === ( $first_input['idempotency_key'] ?? '' ), 'execution forwards the normalized idempotency key' );
npcink_adapter_runner_assert( 'approved' === ( $first_dispatch['context']['approval_status'] ?? '' ), 'execution context includes Core approval context' );
npcink_adapter_runner_assert( 'request-1' === ( $first_dispatch['context']['adapter_request_id'] ?? '' ), 'execution context includes Adapter request context' );
npcink_adapter_runner_assert( 'correlation-1' === ( $first_dispatch['context']['correlation_id'] ?? '' ), 'execution context includes correlation metadata' );
npcink_adapter_runner_assert( 'ok' === ( $result['result']['verification']['upstream'] ?? '' ), 'upstream verification is preserved' );
npcink_adapter_runner_assert( 'matched' === ( $result['result']['verification']['readback'] ?? '' ), 'readback verification is merged' );
npcink_adapter_runner_assert( 'passed' === ( $result['media_alt_live_preflight']['status'] ?? '' ), 'live preflight projection is preserved' );
npcink_adapter_runner_assert( 'draft' === ( $result['post_status_before'] ?? '' ), 'pre-execution post status is preserved' );
npcink_adapter_runner_assert( 'draft' === ( $result['post_status_after'] ?? '' ), 'post-execution post status is preserved' );

$created = $runner->execute(
	'proposal-2',
	array(
		'action_id'    => 'create-one',
		'action_index' => 0,
		'ability_id'   => 'npcink-abilities-toolkit/create-test',
		'post_id'      => 33,
		'input'        => array(
			'idempotency_key' => 'input-key',
			'ignored'         => 'discarded',
		),
	),
	array(),
	'correlation-2',
	array()
);

$forced_input = $dispatch_calls[1]['params']['input'] ?? array();
npcink_adapter_runner_assert(
	array( 'post_id', 'dry_run', 'commit', 'idempotency_key' ) === array_keys( $forced_input ),
	'force_post_input discards unprofiled input fields'
);
npcink_adapter_runner_assert( 33 === ( $forced_input['post_id'] ?? 0 ), 'force_post_input keeps the normalized post id' );
npcink_adapter_runner_assert( 'input-key' === ( $forced_input['idempotency_key'] ?? '' ), 'force_post_input preserves a provided input idempotency key' );
npcink_adapter_runner_assert( 99 === ( $created['post_id'] ?? 0 ), 'post_id_from_result updates the public execution result' );
npcink_adapter_runner_assert( 'publish' === ( $created['post_status_after'] ?? '' ), 'post_id_from_result drives the final status read' );
npcink_adapter_runner_assert( 2 === count( $readback_calls ), 'successful array responses invoke the injected readback seam' );

$upstream_error = new WP_Error( 'upstream_failed' );
$error_runner   = new Execution_Action_Runner(
	$profiles,
	static function () use ( $upstream_error ) {
		return $upstream_error;
	},
	static function (): array {
		return array( 'unexpected' => true );
	}
);
$error_result   = $error_runner->execute(
	'proposal-3',
	array(
		'action_id'  => 'update-failed',
		'ability_id' => 'npcink-abilities-toolkit/update-test',
		'post_id'    => 12,
		'input'      => array(),
	),
	array(),
	'correlation-3',
	array()
);
npcink_adapter_runner_assert( $upstream_error === $error_result, 'upstream WP_Error is returned unchanged' );

fwrite( STDOUT, "Execution action runner behavior: ok\n" );
