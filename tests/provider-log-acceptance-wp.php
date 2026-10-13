<?php
/** Install/remove the exact test loader, or verify the real stored provider row. */
require_once __DIR__ . '/provider-log-correlation-check.php';
$mode   = (string) getenv( 'MAA_PROVIDER_LOG_MODE' );
$marker = (string) getenv( 'MAA_PROVIDER_LOG_MARKER' );
if ( ! preg_match( '/^npcink-provider-log-[a-f0-9]{24}$/', $marker ) ) {
	throw new RuntimeException( 'Invalid fixture marker.' );
}
$loader = WPMU_PLUGIN_DIR . '/' . $marker . '.php';
if ( 'remove' === $mode ) {
	if ( is_file( $loader ) ) {
		unlink( $loader );
	}
	if ( '1' === getenv( 'MAA_PROVIDER_LOG_CLEANUP_POST' ) ) {
		foreach ( get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'draft',
				's'              => $marker,
				'posts_per_page' => 5,
			)
		) as $post ) {
			if ( false !== strpos( $post->post_content, $marker ) ) {
				wp_delete_post( $post->ID, true );
			}
		}
	}
	return;
}
if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$ai_active = is_plugin_active( 'ai/ai.php' );
if ( 'install' === $mode ) {
	if ( ! $ai_active ) {
		echo '[warning] provider correlation skipped: ai plugin absent', "\n";
		return;
	}
	if ( ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
		throw new RuntimeException( 'MU fixture directory unavailable.' );
	}
	$provider   = getenv( 'MAA_PROVIDER_LOG_PROVIDER' ) ? (string) getenv( 'MAA_PROVIDER_LOG_PROVIDER' ) : 'ollama';
	$model      = getenv( 'MAA_PROVIDER_LOG_MODEL' ) ? (string) getenv( 'MAA_PROVIDER_LOG_MODEL' ) : 'qwen3.5:0.8b';
	$ability_id = (string) getenv( 'MAA_PROVIDER_LOG_ABILITY' );
	if ( ! in_array( $ability_id, array( '', 'ai/title-generation' ), true ) ) {
		throw new RuntimeException( 'Unsupported fixture ability.' );
	}
	$source = '<?php require_once ' . var_export( __DIR__ . '/provider-log-fixture.php', true ) . '; maa_adapter_register_provider_log_fixture(' . var_export( $marker, true ) . ', ' . var_export( $provider, true ) . ', ' . var_export( $model, true ) . ', ' . var_export( $ability_id, true ) . ');';
	$handle = fopen( $loader, 'x' );
	if ( ! $handle ) {
		throw new RuntimeException( 'Cannot create exclusive fixture loader.' );
	}
	$written = fwrite( $handle, $source );
	fclose( $handle );
	if ( strlen( $source ) !== $written ) {
		unlink( $loader );
		throw new RuntimeException( 'Cannot write fixture loader.' );
	}
	echo 'installed: provider log fixture', "\n";
	return;
}
if ( 'verify' !== $mode ) {
	throw new RuntimeException( 'Invalid provider fixture mode.' );
}
$execution = json_decode( (string) file_get_contents( (string) getenv( 'MAA_PROVIDER_LOG_EXECUTION_FILE' ) ), true );
$post_id   = (int) ( $execution['post_id'] ?? 0 );
$post      = get_post( $post_id );
if ( ! $post || false === strpos( $post->post_content, $marker ) ) {
	throw new RuntimeException( 'Signed fixture post marker mismatch.' );
}
$fixture = (array) get_post_meta( $post_id, '_npcink_adapter_provider_log_fixture', true );
$manager = $ai_active && class_exists( '\WordPress\AI\Logging\Logging_Integration' ) ? \WordPress\AI\Logging\Logging_Integration::get_log_manager() : null;
try {
	echo maa_adapter_verify_provider_log(
		$ai_active,
		$fixture,
		$execution,
		static function ( $id ) use ( $manager ) {
			return $manager ? $manager->get_log( $id ) : null;
		}
	), "\n";
} catch ( RuntimeException $error ) {
	fwrite( STDERR, '[fail] ' . $error->getMessage() . "\n" );
	exit( 1 );
}
