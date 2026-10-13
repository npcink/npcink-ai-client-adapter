<?php
/** Test-only hook for one marker-scoped signed create-draft acceptance. */
function maa_adapter_register_provider_log_fixture( string $marker, string $provider, string $model, string $ability_id = '' ): void {
	add_action(
		'wp_insert_post',
		static function ( $post_id, $post, $update ) use ( $marker, $provider, $model, $ability_id ) {
			if ( $update || 'post' !== $post->post_type || 'draft' !== $post->post_status || false === strpos( $post->post_content, $marker ) ) {
				return;
			}
			$log_ids = array();
			$capture = static function ( $log_id ) use ( &$log_ids ) {
				$log_ids[] = (string) $log_id;
			};
			add_action( 'wpai_request_logged', $capture );
			$result = array(
				'status'   => 'failed',
				'provider' => $provider,
				'model'    => $model,
				'log_ids'  => array(),
			);
			try {
				if ( ! class_exists( '\WordPress\AI\Logging\Logging_Integration' ) || ! \WordPress\AI\Logging\Logging_Integration::get_log_manager() || ! function_exists( 'wp_ai_client_prompt' ) ) {
					$result['error_code'] = 'ai_logging_unavailable';
				} else {
					if ( 'ai/title-generation' === $ability_id ) {
						$ability = wp_get_ability( $ability_id );
						$output  = $ability ? $ability->execute( array( 'content' => 'A disposable fixture verifies governance identifiers in AI provider request logs.' ) ) : new WP_Error( 'fixture_ability_missing' );
						$text    = is_wp_error( $output ) ? $output : ( $output['title'] ?? '' );
					} else {
						$text = wp_ai_client_prompt( 'Reply with only OK. This is a disposable log correlation acceptance fixture.' )->using_provider( $provider )->using_model_preference( array( $provider, $model ) )->generate_text();
					}
					$result['status'] = is_wp_error( $text ) || ! is_string( $text ) || '' === trim( $text ) ? 'failed' : 'succeeded';
					if ( is_wp_error( $text ) ) {
						$result['error_code'] = $text->get_error_code();
					}
				}
			} catch ( Throwable $error ) {
				$result['error_code'] = 'provider_exception';
			} finally {
				remove_action( 'wpai_request_logged', $capture );
				$result['log_ids'] = array_slice( $log_ids, 0, 10 );
				update_post_meta( $post_id, '_npcink_adapter_provider_log_fixture', $result );
			}
		},
		10,
		3
	);
}
