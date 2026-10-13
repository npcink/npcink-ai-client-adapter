<?php
/**
 * WordPress hook regression checks for approved write log context lifetime.
 *
 * @package NpcinkOpenClawAdapter
 */

$controller = new \Npcink\OpenClawAdapter\Rest\Controller();
$runner     = ( new ReflectionProperty( $controller, 'execution_action_runner' ) )->getValue( $controller );
$dispatch   = ( new ReflectionProperty( $runner, 'dispatch' ) )->getValue( $runner );
$context    = array(
	'proposal_id'            => 'write-context-proposal',
	'correlation_id'         => 'write-context-correlation',
	'adapter_request_id'     => 'write-context-request',
	'ability_id'             => 'npcink-abilities-toolkit/create-draft',
	'governance_source'      => 'npcink-governance-core',
	'authorization'          => 'PRIVATE_RUNTIME_SENTINEL',
	'npcink_governance_core' => array( 'authorization' => 'PRIVATE_NESTED_SENTINEL' ),
);
$previous   = $GLOBALS['npcink_ai_runtime_wp_ability_context'] ?? null;
$GLOBALS['npcink_ai_runtime_wp_ability_context'] = array( 'context' => array( 'previous' => true ) );
$baseline                                        = apply_filters( 'wpai_request_log_context', array( 'existing' => true ), array(), array() );

try {
	foreach ( array( 'success', 'error', 'exception' ) as $mode ) {
		$capture = static function ( $response, $server, $request ) use ( $mode, $context ) {
			if ( '/npcink-write-context-probe/v1/run' !== $request->get_route() ) {
				return $response;
			}
			$log = apply_filters( 'wpai_request_log_context', array( 'existing' => true ), array(), array() );
			foreach ( array( 'proposal_id', 'correlation_id', 'adapter_request_id' ) as $key ) {
				if ( ! hash_equals( $context[ $key ], (string) ( $log[ $key ] ?? '' ) ) ) {
					throw new RuntimeException( 'Write log context is missing ' . $key );
				}
			}
			if ( ! hash_equals( $context['proposal_id'], (string) ( $log['npcink_governance_core']['proposal_id'] ?? '' ) ) || ! hash_equals( $context['correlation_id'], (string) ( $log['npcink_governance_core']['correlation_id'] ?? '' ) ) || true !== ( $log['existing'] ?? false ) ) {
				throw new RuntimeException( 'Write log context must retain existing data and nested Core identifiers.' );
			}
			if ( false !== strpos( wp_json_encode( $log ), 'PRIVATE_' ) ) {
				throw new RuntimeException( 'Runtime authorization leaked into provider context.' );
			}
			if ( 'PRIVATE_RUNTIME_SENTINEL' !== ( $GLOBALS['npcink_ai_runtime_wp_ability_context']['context']['authorization'] ?? '' ) ) {
				throw new RuntimeException( 'Runtime authority was lost while adding log context.' );
			}
			if ( 'exception' === $mode ) {
				throw new RuntimeException( 'write-context-expected-exception' );
			}
			return new WP_REST_Response( array( 'code' => 'write-context-error' ), 'error' === $mode ? 409 : 200 );
		};
		add_filter( 'rest_pre_dispatch', $capture, 10, 3 );
		try {
			$result = $dispatch( $context, 'POST', '/npcink-write-context-probe/v1/run', array(), false, true );
			if ( 'exception' === $mode || ( 'error' === $mode ) !== is_wp_error( $result ) ) {
				throw new RuntimeException( 'Write dispatch result changed.' );
			}
		} catch ( RuntimeException $error ) {
			if ( 'exception' !== $mode || 'write-context-expected-exception' !== $error->getMessage() ) {
				throw $error;
			}
		} finally {
			remove_filter( 'rest_pre_dispatch', $capture, 10 );
		}
		if ( apply_filters( 'wpai_request_log_context', array( 'existing' => true ), array(), array() ) !== $baseline || array( 'context' => array( 'previous' => true ) ) !== $GLOBALS['npcink_ai_runtime_wp_ability_context'] ) {
			throw new RuntimeException( 'Write dispatch context survived the ' . $mode . ' path.' );
		}
		echo '[ok] write provider log identifiers, private authority, and cleanup: ', $mode, "\n";
	}
} finally {
	if ( null === $previous ) {
		unset( $GLOBALS['npcink_ai_runtime_wp_ability_context'] );
	} else {
		$GLOBALS['npcink_ai_runtime_wp_ability_context'] = $previous;
	}
}
