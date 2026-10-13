<?php
require __DIR__ . '/provider-log-correlation-check.php';
$execution      = array(
	'proposal_id'        => 'proposal-fixture',
	'correlation_id'     => 'correlation-fixture',
	'adapter_request_id' => 'request-fixture',
);
$fixture        = array(
	'status'   => 'succeeded',
	'provider' => 'ollama',
	'model'    => 'fixture-model',
	'log_ids'  => array( 'log-fixture' ),
);
$row            = array(
	'status'   => 'success',
	'provider' => 'ollama',
	'model'    => 'fixture-model',
	'context'  => $execution + array( 'npcink_governance_core' => $execution ),
);
$checks         = 0;
$expect_failure = static function ( $candidate, $record, $result ) use ( &$checks ) {
	try {
		maa_adapter_verify_provider_log(
			true,
			$candidate,
			$result,
			static function () use ( $record ) {
				return $record;
			}
		);
	} catch ( RuntimeException $error ) {
		++$checks;
		return;
	}
	throw new RuntimeException( 'Invalid provider evidence unexpectedly passed.' );
};
if ( 'skipped: ai plugin absent' !== maa_adapter_verify_provider_log(
	false,
	array(),
	array(),
	static function () {
		throw new RuntimeException( 'Absent plugin must not read logs.' );
	}
) ) {
	throw new RuntimeException( 'Absent plugin skip failed.' );
}
++$checks;
if ( 0 !== strpos(
	maa_adapter_verify_provider_log(
		true,
		$fixture,
		$execution,
		static function () use ( $row ) {
			return $row;
		}
	),
	'passed:'
) ) {
	throw new RuntimeException( 'Matching persisted log did not pass.' );
}
++$checks;
$expect_failure( $fixture, null, $execution );
$expect_failure( array(), $row, $execution );
$expect_failure( $fixture, $row, array() );
foreach ( array( 'proposal_id', 'correlation_id', 'adapter_request_id' ) as $field ) {
	$bad                      = $row;
	$bad['context'][ $field ] = 'unrelated';
	$expect_failure( $fixture, $bad, $execution );
}
$bad = $row;
$bad['context']['npcink_governance_core']['correlation_id'] = 'unrelated';
$expect_failure( $fixture, $bad, $execution );
$bad           = $row;
$bad['status'] = 'error';
$expect_failure( $fixture, $bad, $execution );
echo "Provider log correlation behavior: {$checks} checks passed\n";
