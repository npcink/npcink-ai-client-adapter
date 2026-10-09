<?php
/**
 * Focused behavior probe for ADR-013 supplement grant queue seeding.
 *
 * Pins the seeded-vs-shifted key state: array_shift consumption of a
 * preflight grant leaves the queue key in place with an empty inner array,
 * and a Core-recorded evidence grant seeded afterwards must still land on
 * that drained key so the re-run readback finds fresh authorization.
 *
 * @package NpcinkOpenClawAdapter
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

function sanitize_text_field( $str ): string {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $str ) ) ?: '' );
}

function sanitize_key( $key ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) ?: '';
}

function maa_supplement_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

function maa_supplement_invoke( object $object, string $method, array $args = array() ) {
	$reflection = new ReflectionMethod( $object, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $object, $args );
}

require_once dirname( __DIR__ ) . '/includes/Rest/Contract_Metadata.php';
require_once dirname( __DIR__ ) . '/includes/Rest/Signing_Auth.php';
require_once dirname( __DIR__ ) . '/includes/Rest/Upstream_Dispatch.php';
require_once dirname( __DIR__ ) . '/includes/Rest/Dependency_Status.php';
require_once dirname( __DIR__ ) . '/includes/Rest/Execution_Records.php';
require_once dirname( __DIR__ ) . '/includes/Rest/Preflight_Handoffs.php';
require_once dirname( __DIR__ ) . '/includes/Rest/Read_Governance.php';
require_once dirname( __DIR__ ) . '/includes/Rest/Controller.php';

$reflection = new ReflectionClass( \Npcink\OpenClawAdapter\Rest\Controller::class );
$controller = $reflection->newInstanceWithoutConstructor();

$queues_property = $reflection->getProperty( 'verification_grant_queues' );
$queues_property->setAccessible( true );

$read_ability = 'npcink-abilities-toolkit/get-post-blocks';
$queue_key    = $read_ability . '|post:42';
$correlation  = 'probe-correlation-1';

// Preflight seeding left one grant on the key, and the failed original
// readback consumed it exactly the way block_write_readback_verification()
// does: array_shift empties the inner queue but keeps the key set.
$queues_property->setValue(
	$controller,
	array(
		$correlation => array(
			$queue_key => array(
				array( 'request_id' => 'preflight-grant-1', 'input' => array( 'post_id' => 42 ) ),
			),
		),
	)
);
$queues = $queues_property->getValue( $controller );
array_shift( $queues[ $correlation ][ $queue_key ] );
$queues_property->setValue( $controller, $queues );

$queues = $queues_property->getValue( $controller );
maa_supplement_assert( array_key_exists( $queue_key, $queues[ $correlation ] ), 'Drained key stays set after array_shift consumption.' );
maa_supplement_assert( 0 === count( $queues[ $correlation ][ $queue_key ] ), 'Drained key carries an empty inner queue.' );

// Seeding a Core-recorded evidence grant must append to the drained key.
maa_supplement_invoke(
	$controller,
	'seed_supplement_verification_grants',
	array(
		$correlation,
		array(
			array(
				'request_id' => 'recorded-grant-2',
				'ability_id' => $read_ability,
				'input'      => array(
					'post_id'              => 42,
					'include_inner_blocks' => true,
				),
			),
		),
	)
);

$queues = $queues_property->getValue( $controller );
maa_supplement_assert( array_key_exists( $queue_key, $queues[ $correlation ] ), 'Seeded key stays present on the drained queue set.' );
maa_supplement_assert( 1 === count( $queues[ $correlation ][ $queue_key ] ), 'Recorded-evidence grant appends to the drained key instead of being discarded.' );
maa_supplement_assert( 'recorded-grant-2' === $queues[ $correlation ][ $queue_key ][0]['request_id'], 'The next readback on the drained key takes the freshly appended grant.' );

// The re-run lookup predicate from block_write_readback_verification() must
// treat the reseeded key as authorization-bearing again.
maa_supplement_assert( ! empty( $queues[ $correlation ][ $queue_key ] ) && is_array( $queues[ $correlation ][ $queue_key ] ), 'The re-run lookup predicate finds authorization on the reseeded key.' );

// A second grant on the same key appends FIFO instead of replacing.
maa_supplement_invoke(
	$controller,
	'seed_supplement_verification_grants',
	array(
		$correlation,
		array(
			array(
				'request_id' => 'recorded-grant-3',
				'ability_id' => $read_ability,
				'input'      => array( 'post_id' => 42 ),
			),
		),
	)
);
$queues = $queues_property->getValue( $controller );
maa_supplement_assert( 2 === count( $queues[ $correlation ][ $queue_key ] ), 'Repeated seeding appends FIFO on the same key.' );

// Unusable grants never land: missing request id, missing ability id, and
// inputs without object addressing (empty signature) are all skipped.
maa_supplement_invoke(
	$controller,
	'seed_supplement_verification_grants',
	array(
		$correlation,
		array(
			array(
				'request_id' => '',
				'ability_id' => $read_ability,
				'input'      => array( 'post_id' => 42 ),
			),
			array(
				'request_id' => 'recorded-grant-4',
				'ability_id' => '',
				'input'      => array( 'post_id' => 42 ),
			),
			array(
				'request_id' => 'recorded-grant-5',
				'ability_id' => $read_ability,
				'input'      => array( 'include_inner_blocks' => true ),
			),
			array( 'request_id' => 'recorded-grant-6' ),
		),
	)
);
$queues = $queues_property->getValue( $controller );
maa_supplement_assert( 2 === count( $queues[ $correlation ][ $queue_key ] ), 'Unusable grants are skipped without touching the queue.' );

// A fresh correlation with no preflight map initializes its queue set.
maa_supplement_invoke(
	$controller,
	'seed_supplement_verification_grants',
	array(
		'probe-correlation-2',
		array(
			array(
				'request_id' => 'recorded-grant-7',
				'ability_id' => 'npcink-abilities-toolkit/get-template-blocks',
				'input'      => array( 'slug' => 'single' ),
			),
		),
	)
);
$queues = $queues_property->getValue( $controller );
maa_supplement_assert(
	1 === count( $queues['probe-correlation-2']['npcink-abilities-toolkit/get-template-blocks|slug:single'] ?? array() ),
	'A fresh correlation initializes its queue set with the slug-addressed grant.'
);

fwrite( STDOUT, 'verification supplement queue seeding: ok' . PHP_EOL );
