<?php
/**
 * Real WordPress smoke test for Npcink AI Client Adapter.
 *
 * @package NpcinkOpenClawAdapter
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "WordPress is not loaded.\n" );
	exit( 1 );
}

$maa_adapter_smoke_run_id = wp_generate_uuid4();

/**
 * Assertion helper.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 */
function maa_adapter_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, '[fail] ' . $message . "\n" );
		exit( 1 );
	}

	echo '[ok] ' . $message . "\n";
}

/**
 * Returns the current smoke run id for fixture tagging.
 *
 * @return string
 */
function maa_adapter_smoke_run_id(): string {
	global $maa_adapter_smoke_run_id;

	return (string) $maa_adapter_smoke_run_id;
}

/**
 * Checks whether help route rows include a route.
 *
 * @param array<string,mixed> $help Help response.
 * @param string              $method HTTP method.
 * @param string              $path Route path.
 * @return bool
 */
function maa_adapter_smoke_help_has_route( array $help, string $method, string $path ): bool {
	foreach ( (array) ( $help['routes'] ?? array() ) as $route ) {
		if ( ! is_array( $route ) ) {
			continue;
		}

		if ( $method === (string) ( $route['method'] ?? '' ) && $path === (string) ( $route['path'] ?? '' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Dispatches an adapter REST request.
 *
 * @param string              $method HTTP method.
 * @param string              $route REST route.
 * @param array<string,mixed> $params Params.
 * @return array<string,mixed>
 */
function maa_adapter_smoke_rest( string $method, string $route, array $params = array() ): array {
	$result = maa_adapter_smoke_rest_result( $method, $route, $params );
	maa_adapter_smoke_assert( $result['status'] >= 200 && $result['status'] < 300, $method . ' ' . $route . ' returned HTTP ' . $result['status'] );

	return is_array( $result['data'] ) ? $result['data'] : array();
}

/**
 * Asserts that an unsupported content intent stops before proposal handoff.
 *
 * @param string $prompt Customer wording.
 * @param string $label Assertion label.
 * @param string $expected_reason Expected unsupported reason.
 * @return void
 */
function maa_adapter_smoke_assert_content_intent_fails_closed( string $prompt, string $label, string $expected_reason ): void {
	$response = maa_adapter_smoke_rest(
		'POST',
		'/npcink-openclaw-adapter/v1/run-read-ability',
		array(
			'ability_id' => 'npcink-abilities-toolkit/route-content-intent',
			'input'      => array(
				'prompt' => $prompt,
			),
		)
	);

	$data  = is_array( $response['result']['data'] ?? null ) ? $response['result']['data'] : array();
	$route = is_array( $data['route'] ?? null ) ? $data['route'] : array();

	maa_adapter_smoke_assert( 'content_intent_route' === (string) ( $data['artifact_type'] ?? '' ), $label . ' returns content_intent_route' );
	maa_adapter_smoke_assert( false === (bool) ( $data['prompt_is_authorization'] ?? true ), $label . ' prompt is not authorization' );
	maa_adapter_smoke_assert( 'unsupported' === (string) ( $route['route'] ?? '' ), $label . ' route is unsupported' );
	maa_adapter_smoke_assert( false === (bool) ( $route['supported'] ?? true ), $label . ' supported flag is false' );
	maa_adapter_smoke_assert( true === (bool) ( $route['needs_clarification'] ?? false ), $label . ' requests clarification' );
	maa_adapter_smoke_assert( $expected_reason === (string) ( $route['unsupported_reason'] ?? '' ), $label . ' reports expected unsupported reason' );
	maa_adapter_smoke_assert( '' === (string) ( $route['plan_ability_id'] ?? '' ), $label . ' has no plan ability' );
	maa_adapter_smoke_assert( array() === (array) ( $route['final_write_ability_ids'] ?? array() ), $label . ' has no final write abilities' );
	maa_adapter_smoke_assert( ! array_key_exists( 'write_actions', $data ), $label . ' does not emit write_actions' );
	maa_adapter_smoke_assert( in_array( 'Do not submit a Core proposal for unsupported route output.', (array) ( $data['next_steps'] ?? array() ), true ), $label . ' tells client not to submit proposal' );
}

/**
 * Finds an AI Request Logs item by adapter context identifiers.
 *
 * @param string $proposal_id Proposal id.
 * @param string $correlation_id Correlation id.
 * @return array<string,mixed>|null
 */
function maa_adapter_smoke_find_ai_request_log( string $proposal_id, string $correlation_id ): ?array {
	$logs = maa_adapter_smoke_rest(
		'GET',
		'/ai/v1/logs',
		array(
			'type'     => 'ai_client',
			'status'   => 'success',
			'per_page' => 100,
		)
	);

	foreach ( $logs as $log ) {
		if ( ! is_array( $log ) ) {
			continue;
		}

		$context = is_array( $log['context'] ?? null ) ? $log['context'] : array();
		if (
			$proposal_id === (string) ( $context['proposal_id'] ?? '' )
			&& $correlation_id === (string) ( $context['correlation_id'] ?? '' )
		) {
			return $log;
		}
	}

	return null;
}

/**
 * Dispatches an adapter REST request and returns status/data.
 *
 * @param string              $method HTTP method.
 * @param string              $route REST route.
 * @param array<string,mixed> $params Params.
 * @return array{status:int,data:mixed}
 */
function maa_adapter_smoke_rest_result( string $method, string $route, array $params = array() ): array {
	wp_set_current_user( 1 );

	$request = new WP_REST_Request( $method, $route );
	if ( 'GET' === strtoupper( $method ) ) {
		$request->set_query_params( $params );
	} else {
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
	}

	$response = rest_do_request( $request );

	return array(
		'status' => (int) $response->get_status(),
		'data'   => $response->get_data(),
	);
}

/**
 * Asserts the current machine-readable Adapter contract snapshot.
 *
 * @param array<string,mixed> $payload REST payload.
 * @param string              $label Assertion label.
 * @return void
 */
function maa_adapter_smoke_assert_contract_snapshot( array $payload, string $label ): void {
	$expected = array(
		'schema_version'                       => 'npcink_openclaw_adapter_contract.v1',
		'adapter_contract_version'             => '2',
		'client_policy_version'                => '1',
		'execution_profile_registry_version'   => '1',
		'supported_plan_abilities_version'     => '1',
		'core_contract_min_version'            => '1',
		'core_plugin_min_version'              => '0.1.0',
		'toolkit_contract_min_version'         => '1',
		'toolkit_plugin_min_version'           => '0.5.1',
		'execution_profile_registry_hash'      => 'sha256:d31e94672ebeef0a2822fe5b4652d675ca0044fd77ef9df7a6afd63a1d477bc4',
		'supported_execute_ability_ids_hash'   => 'sha256:dfa1e7d90d13c593a3c4a1fbbd104af3535e42f5fab03274f36099037ab6d97d',
		'supported_plan_ability_ids_hash'      => 'sha256:58ea7d6e59d57f4026c6c0dacf8b63273157af5f0ca0e416de19f40a98e255be',
		'max_execution_actions'                => 200,
		'core_proxy_execute'                   => false,
		'commit_execution'                     => false,
	);
	$contract = is_array( $payload['contract'] ?? null ) ? $payload['contract'] : array();

	maa_adapter_smoke_assert( array_keys( $expected ) === array_keys( $contract ), $label . ' contract snapshot exposes only expected keys' );
	foreach ( $expected as $key => $value ) {
		maa_adapter_smoke_assert( $value === ( $contract[ $key ] ?? null ), $label . ' contract snapshot matches ' . $key );
	}
}

if ( ! function_exists( 'npcink_cloud_addon_build_media_derivative_optimization_payload' ) ) {
	/**
	 * Local smoke double for the Cloud Addon optimization payload builder.
	 *
	 * @param array<string,mixed> $ability_response Ability response.
	 * @param array<string,mixed> $cloud_result Cloud result.
	 * @param array<string,mixed> $artifact Derivative artifact.
	 * @param array<string,mixed> $media_details_input Reviewed media details input.
	 * @return array<string,mixed>|WP_Error
	 */
	function npcink_cloud_addon_build_media_derivative_optimization_payload( array $ability_response, array $cloud_result, array $artifact, array $media_details_input ) {
		$attachment_id = absint( $ability_response['data']['attachment_id'] ?? ( $ability_response['attachment_id'] ?? ( $artifact['attachment_id'] ?? 0 ) ) );
		$artifact_id   = sanitize_text_field( (string) ( $artifact['artifact_id'] ?? '' ) );
		if ( $attachment_id <= 0 || '' === $artifact_id ) {
			return new WP_Error( 'maa_adapter_smoke_derivative_payload_invalid', 'Smoke derivative payload requires attachment_id and artifact_id.', array( 'status' => 400 ) );
		}

		$contract = is_array( $ability_response['data'] ?? null ) ? $ability_response['data'] : array();
		$source_asset = is_array( $contract['cloud_job_payload']['source_asset'] ?? null ) ? $contract['cloud_job_payload']['source_asset'] : array();
		$cloud_data = is_array( $cloud_result['data'] ?? null ) ? $cloud_result['data'] : $cloud_result;
		$derivative = is_array( $cloud_data['derivative'] ?? null ) ? $cloud_data['derivative'] : array();
		$content_reference_repairs_preview = is_array( $contract['content_reference_repairs_preview'] ?? null ) ? $contract['content_reference_repairs_preview'] : array();

		$payload = array(
			'attachment_id' => $attachment_id,
			'artifact'      => $artifact,
			'original'      => array(
				'mime_type'      => sanitize_text_field( (string) ( $source_asset['mime_type'] ?? 'image/jpeg' ) ),
				'width'          => absint( $source_asset['width'] ?? 1600 ),
				'height'         => absint( $source_asset['height'] ?? 900 ),
				'filesize_bytes' => absint( $source_asset['filesize_bytes'] ?? 734003 ),
			),
			'derivative'    => array(
				'mime_type'      => sanitize_text_field( (string) ( $derivative['mime_type'] ?? ( $artifact['mime_type'] ?? 'image/webp' ) ) ),
				'width'          => absint( $derivative['width'] ?? ( $artifact['width'] ?? 1600 ) ),
				'height'         => absint( $derivative['height'] ?? ( $artifact['height'] ?? 900 ) ),
				'filesize_bytes' => absint( $derivative['filesize_bytes'] ?? ( $artifact['filesize_bytes'] ?? 196608 ) ),
			),
		);
		if ( ! empty( $content_reference_repairs_preview ) ) {
			$payload['content_reference_repairs_preview'] = $content_reference_repairs_preview;
		}

		return array(
			'contract_version'                             => 'media_derivative_cloud_optimization_payload.v1',
			'proposal_payload'                             => $payload,
			'media_optimization_plan'                       => array(
				'ability_id' => 'npcink-abilities-toolkit/build-media-optimization-plan',
				'input'      => $media_details_input,
			),
			'core_proposal_required'                        => true,
			'commit_execution'                              => false,
			'proposal_ready'                                => true,
			'preferred_core_route'                          => 'POST /proposals/from-plan',
			'legacy_derivative_proposal_payload_available'  => true,
			'required_plan_ability_id'                      => 'npcink-abilities-toolkit/build-media-optimization-plan',
			'from_plan_request'                             => array(
				'ability_id' => 'npcink-abilities-toolkit/build-media-optimization-plan',
				'input'      => $media_details_input,
			),
		);
	}
}

$GLOBALS['maa_adapter_smoke_cloud_artifact_downloads'] = array();

/**
 * Provides local Cloud artifact bytes for final-write smoke tests.
 *
 * @param mixed               $download Existing filtered download.
 * @param array<string,mixed> $artifact Artifact descriptor.
 * @return mixed
 */
function maa_adapter_smoke_cloud_artifact_download( $download, array $artifact ) {
	if ( null !== $download ) {
		return $download;
	}

	$artifact_id = sanitize_text_field( (string) ( $artifact['artifact_id'] ?? '' ) );
	$downloads   = is_array( $GLOBALS['maa_adapter_smoke_cloud_artifact_downloads'] ?? null ) ? $GLOBALS['maa_adapter_smoke_cloud_artifact_downloads'] : array();
	if ( '' === $artifact_id || ! isset( $downloads[ $artifact_id ] ) || ! is_array( $downloads[ $artifact_id ] ) ) {
		return null;
	}

	return $downloads[ $artifact_id ];
}
add_filter( 'npcink_abilities_toolkit_cloud_media_derivative_artifact_download', 'maa_adapter_smoke_cloud_artifact_download', 10, 2 );

/**
 * Captured Adapter observability events.
 *
 * @var array<int,array<string,mixed>>
 */
$GLOBALS['maa_adapter_smoke_observability_events'] = array();

/**
 * Captures Adapter observability events for smoke assertions.
 *
 * @param mixed $event Event payload.
 * @return void
 */
function maa_adapter_smoke_capture_observability_event( $event ): void {
	if ( ! is_array( $event ) || ! in_array( (string) ( $event['plugin_slug'] ?? '' ), array( 'npcink-openclaw-adapter', 'npcink-ai-client-adapter' ), true ) ) {
		return;
	}

	$GLOBALS['maa_adapter_smoke_observability_events'][] = $event;
}
add_action( 'npcink_openclaw_adapter_observability_event', 'maa_adapter_smoke_capture_observability_event' );

/**
 * Finds the latest captured Adapter observability event.
 *
 * @param string $event_kind Event kind.
 * @param string $status Status, or empty for any.
 * @param string $route Route, or empty for any.
 * @return array<string,mixed>
 */
function maa_adapter_smoke_observability_event( string $event_kind, string $status = '', string $route = '' ): array {
	$maa_adapter_smoke_observability_events = is_array( $GLOBALS['maa_adapter_smoke_observability_events'] ?? null ) ? $GLOBALS['maa_adapter_smoke_observability_events'] : array();
	for ( $index = count( $maa_adapter_smoke_observability_events ) - 1; $index >= 0; --$index ) {
		$event = $maa_adapter_smoke_observability_events[ $index ];
		if ( $event_kind !== (string) ( $event['event_kind'] ?? '' ) ) {
			continue;
		}
		if ( '' !== $status && $status !== (string) ( $event['status'] ?? '' ) ) {
			continue;
		}
		if ( '' !== $route && $route !== (string) ( $event['route'] ?? '' ) ) {
			continue;
		}

		return $event;
	}

	return array();
}

/**
 * Returns whether a payload tree contains a key.
 *
 * @param mixed  $value Payload value.
 * @param string $needle Key to find.
 * @return bool
 */
function maa_adapter_smoke_payload_has_key( $value, string $needle ): bool {
	if ( ! is_array( $value ) ) {
		return false;
	}

	foreach ( $value as $key => $child ) {
		if ( $needle === (string) $key || maa_adapter_smoke_payload_has_key( $child, $needle ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Asserts an observability event is metadata-only.
 *
 * @param array<string,mixed> $event Event payload.
 * @param string              $label Assertion label.
 * @return void
 */
function maa_adapter_smoke_assert_observability_safe( array $event, string $label ): void {
	$event_id = (string) ( $event['event_id'] ?? '' );
	maa_adapter_smoke_assert( '' !== $event_id, $label . ' includes a stable event id' );
	maa_adapter_smoke_assert( 1 === preg_match( '/^[a-z0-9_]+$/', $event_id ), $label . ' event id uses safe characters' );
	foreach ( array( 'input', 'plan', 'preview', 'response', 'upstream_data', 'authorization', 'token', 'secret', 'prompt', 'content', 'write_actions' ) as $forbidden ) {
		maa_adapter_smoke_assert( ! maa_adapter_smoke_payload_has_key( $event, $forbidden ), $label . ' excludes raw key ' . $forbidden );
	}
}

/**
 * Asserts a payload tree does not expose a sensitive string.
 *
 * @param mixed  $payload Payload.
 * @param string $needle Sensitive string.
 * @param string $label Assertion label.
 * @return void
 */
function maa_adapter_smoke_assert_payload_excludes_string( $payload, string $needle, string $label ): void {
	$json = wp_json_encode( $payload );
	maa_adapter_smoke_assert( is_string( $json ) && false === strpos( $json, $needle ), $label . ' does not expose token value' );
}

/**
 * Returns capability rows keyed by ability id.
 *
 * @param array<string,mixed> $capabilities Capabilities response.
 * @return array<string,array<string,mixed>>
 */
function maa_adapter_smoke_capabilities_by_id( array $capabilities ): array {
	$by_id = array();
	foreach ( (array) ( $capabilities['items'] ?? array() ) as $item ) {
		if ( is_array( $item ) && '' !== (string) ( $item['ability_id'] ?? '' ) ) {
			$by_id[ (string) $item['ability_id'] ] = $item;
		}
	}

	return $by_id;
}

/**
 * Returns a configured AI text generation provider/model for smoke.
 *
 * @return array{provider:string,model:string}
 */
function maa_adapter_smoke_text_generation_model(): array {
	$providers = maa_adapter_smoke_rest(
		'GET',
		'/ai/v1/providers',
		array(
			'capability' => 'text_generation',
		)
	);

	$fallback = array(
		'provider' => '',
		'model'    => '',
	);

	foreach ( $providers as $provider ) {
		if ( ! is_array( $provider ) ) {
			continue;
		}

		$provider_id = (string) ( $provider['id'] ?? '' );
		foreach ( (array) ( $provider['models'] ?? array() ) as $model ) {
			if ( ! is_array( $model ) ) {
				continue;
			}

			$model_id = (string) ( $model['id'] ?? '' );
			if ( '' === $provider_id || '' === $model_id ) {
				continue;
			}

			if ( 'ollama' === $provider_id && 'qwen3.5:0.8b' === $model_id ) {
				return array(
					'provider' => $provider_id,
					'model'    => $model_id,
				);
			}

			if ( '' === $fallback['provider'] ) {
				$fallback = array(
					'provider' => $provider_id,
					'model'    => $model_id,
				);
			}
		}
	}

	maa_adapter_smoke_assert( '' !== $fallback['provider'] && '' !== $fallback['model'], 'AI provider smoke found a configured text generation model' );
	return $fallback;
}

/**
 * Returns the registered smoke fixtures that must be deleted on any exit path.
 *
 * @return array<string,mixed>
 */
function &maa_adapter_smoke_fixture_registry(): array {
	if ( ! isset( $GLOBALS['maa_adapter_smoke_fixture_registry'] ) || ! is_array( $GLOBALS['maa_adapter_smoke_fixture_registry'] ) ) {
		$GLOBALS['maa_adapter_smoke_fixture_registry'] = array(
			'proposal_ids'     => array(),
			'read_request_ids' => array(),
			'attachment_ids'   => array(),
			'post_ids'         => array(),
			'comment_ids'      => array(),
			'terms'            => array(),
			'core_app_ids'     => array(),
			'core_app_key_ids' => array(),
			'cleaned'          => false,
		);
	}

	return $GLOBALS['maa_adapter_smoke_fixture_registry'];
}

/**
 * Returns visual acceptance fixtures created by Gutenberg plan smoke coverage.
 *
 * @return array<string,mixed>
 */
function &maa_adapter_smoke_visual_acceptance_registry(): array {
	if ( ! isset( $GLOBALS['maa_adapter_smoke_visual_acceptance_registry'] ) || ! is_array( $GLOBALS['maa_adapter_smoke_visual_acceptance_registry'] ) ) {
		$GLOBALS['maa_adapter_smoke_visual_acceptance_registry'] = array(
			'fixtures'       => array(),
			'post_ids'       => array(),
			'attachment_ids' => array(),
		);
	}

	return $GLOBALS['maa_adapter_smoke_visual_acceptance_registry'];
}

/**
 * Whether local smoke should keep visual acceptance fixtures for browser review.
 *
 * @return bool
 */
function maa_adapter_smoke_keep_visual_acceptance_fixtures(): bool {
	$value = strtolower( trim( (string) getenv( 'MAA_ADAPTER_KEEP_VISUAL_ACCEPTANCE_FIXTURES' ) ) );
	return in_array( $value, array( '1', 'true', 'yes' ), true );
}

/**
 * Returns canonical viewport targets for browser visual acceptance.
 *
 * @return array<int,array<string,mixed>>
 */
function maa_adapter_smoke_visual_acceptance_viewports(): array {
	return array(
		array(
			'name'   => 'desktop',
			'width'  => 1440,
			'height' => 1000,
		),
		array(
			'name'   => 'tablet',
			'width'  => 768,
			'height' => 1024,
		),
		array(
			'name'   => 'mobile',
			'width'  => 390,
			'height' => 844,
		),
	);
}

/**
 * Flattens a parsed Gutenberg block tree.
 *
 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
 * @return array<int,array<string,mixed>>
 */
function maa_adapter_smoke_flatten_blocks( array $blocks ): array {
	$flat = array();
	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}
		$flat[] = $block;
		$inner_blocks = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
		if ( ! empty( $inner_blocks ) ) {
			$flat = array_merge( $flat, maa_adapter_smoke_flatten_blocks( $inner_blocks ) );
		}
	}

	return $flat;
}

/**
 * Counts parsed blocks by block name.
 *
 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
 * @param string                         $block_name Block name.
 * @return int
 */
function maa_adapter_smoke_count_blocks_by_name( array $blocks, string $block_name ): int {
	$count = 0;
	foreach ( maa_adapter_smoke_flatten_blocks( $blocks ) as $block ) {
		if ( $block_name === (string) ( $block['blockName'] ?? '' ) ) {
			++$count;
		}
	}

	return $count;
}

/**
 * Returns whether a block has style.spacing.padding.
 *
 * @param array<string,mixed> $block Parsed block.
 * @return bool
 */
function maa_adapter_smoke_block_has_padding_style( array $block ): bool {
	$attrs   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
	$style   = is_array( $attrs['style'] ?? null ) ? $attrs['style'] : array();
	$spacing = is_array( $style['spacing'] ?? null ) ? $style['spacing'] : array();
	$padding = is_array( $spacing['padding'] ?? null ) ? $spacing['padding'] : array();

	return ! empty( array_filter( array_map( 'strval', $padding ) ) );
}

/**
 * Counts blocks with explicit Gutenberg-native padding.
 *
 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
 * @return int
 */
function maa_adapter_smoke_count_padded_blocks( array $blocks ): int {
	$count = 0;
	foreach ( maa_adapter_smoke_flatten_blocks( $blocks ) as $block ) {
		if ( maa_adapter_smoke_block_has_padding_style( $block ) ) {
			++$count;
		}
	}

	return $count;
}

/**
 * Asserts basic machine-checkable Gutenberg content quality.
 *
 * This catches regressions that previously appeared as broken images, blank
 * headings, or flat sections without Gutenberg-native spacing.
 *
 * @param string                    $label Assertion label prefix.
 * @param string                    $post_content Post content.
 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
 * @param int                       $minimum_padded_blocks Minimum padded blocks.
 * @return void
 */
function maa_adapter_smoke_assert_gutenberg_content_quality( string $label, string $post_content, array $blocks, int $minimum_padded_blocks ): void {
	$flat_blocks = maa_adapter_smoke_flatten_blocks( $blocks );
	maa_adapter_smoke_assert( ! empty( $flat_blocks ), $label . ' has parsed Gutenberg blocks' );
	maa_adapter_smoke_assert( 0 === preg_match( '/<h[1-6][^>]*>(?:\s|&nbsp;|<br\s*\/?>)*<\/h[1-6]>/i', $post_content ), $label . ' has no empty heading markup' );
	maa_adapter_smoke_assert( 0 === preg_match( '/<p[^>]*>(?:\s|&nbsp;|<br\s*\/?>)*<\/p>/i', $post_content ), $label . ' has no empty paragraph markup' );
	maa_adapter_smoke_assert( maa_adapter_smoke_count_padded_blocks( $blocks ) >= $minimum_padded_blocks, $label . ' keeps Gutenberg-native spacing on key sections' );
}

/**
 * Asserts all image tags in a Gutenberg payload have a concrete src and alt.
 *
 * @param string $label Assertion label prefix.
 * @param string $post_content Post content.
 * @return void
 */
function maa_adapter_smoke_assert_gutenberg_images_are_complete( string $label, string $post_content ): void {
	$image_count = preg_match_all( '/<img\b[^>]*>/i', $post_content, $matches );
	maa_adapter_smoke_assert( false !== $image_count && $image_count > 0, $label . ' contains rendered image markup' );
	foreach ( (array) ( $matches[0] ?? array() ) as $image_markup ) {
		maa_adapter_smoke_assert( 1 === preg_match( '/\ssrc=(["\'])(?!\1)[^"\']+\1/i', $image_markup ), $label . ' image has non-empty src' );
		maa_adapter_smoke_assert( 1 === preg_match( '/\salt=(["\'])(?!\1)[^"\']+\1/i', $image_markup ), $label . ' image has non-empty alt' );
	}
}

/**
 * Records a created Gutenberg plan fixture for optional browser acceptance.
 *
 * @param string              $fixture_type Fixture type.
 * @param int                 $post_id Created post id.
 * @param int[]               $attachment_ids Attachment ids needed by the fixture.
 * @param array<string,mixed> $structure_signals Machine-checked structure signals.
 * @return array<string,mixed>
 */
function maa_adapter_smoke_record_gutenberg_visual_acceptance_fixture( string $fixture_type, int $post_id, array $attachment_ids, array $structure_signals ): array {
	$original_post_status = (string) get_post_status( $post_id );
	if ( maa_adapter_smoke_keep_visual_acceptance_fixtures() && 'publish' !== $original_post_status ) {
		$publish_result = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			),
			true
		);
		maa_adapter_smoke_assert( ! is_wp_error( $publish_result ), 'adapter visual acceptance fixture is temporarily published for anonymous browser rendering' );
	}

	$fixture = array(
		'fixture_type'                  => sanitize_key( $fixture_type ),
		'post_id'                       => $post_id,
		'post_type'                     => (string) get_post_type( $post_id ),
		'post_status'                   => $original_post_status,
		'visual_acceptance_post_status' => (string) get_post_status( $post_id ),
		'attachment_ids'                => array_values( array_unique( array_filter( array_map( 'absint', $attachment_ids ) ) ) ),
		'front_end_url'                 => (string) get_permalink( $post_id ),
		'block_editor_url'              => (string) get_edit_post_link( $post_id, 'raw' ),
		'viewports'                     => maa_adapter_smoke_visual_acceptance_viewports(),
		'manual_checks'                 => array(
			'front_end_has_no_horizontal_overflow',
			'block_editor_has_no_invalid_block_recovery_prompt',
			'core_blocks_remain_individually_editable',
			'mobile_layout_wraps_or_stacks_without_clipping',
		),
		'structure_signals'             => $structure_signals,
		'fixtures_retained'             => maa_adapter_smoke_keep_visual_acceptance_fixtures(),
	);

	$registry =& maa_adapter_smoke_visual_acceptance_registry();
	$registry['fixtures'][] = $fixture;
	$registry['post_ids'][] = $post_id;
	foreach ( $attachment_ids as $attachment_id ) {
		$registry['attachment_ids'][] = absint( $attachment_id );
	}

	return $fixture;
}

/**
 * Writes optional browser acceptance fixture metadata to a JSON file.
 *
 * @return void
 */
function maa_adapter_smoke_export_visual_acceptance_fixtures(): void {
	$output_path = trim( (string) getenv( 'MAA_ADAPTER_VISUAL_ACCEPTANCE_OUT' ) );
	if ( '' === $output_path ) {
		return;
	}

	$registry = maa_adapter_smoke_visual_acceptance_registry();
	$payload  = array(
		'generated_at'             => gmdate( 'c' ),
		'fixtures_retained'        => maa_adapter_smoke_keep_visual_acceptance_fixtures(),
		'fixture_retention_env'    => 'MAA_ADAPTER_KEEP_VISUAL_ACCEPTANCE_FIXTURES',
		'viewports'                => maa_adapter_smoke_visual_acceptance_viewports(),
		'fixtures'                 => array_values( (array) ( $registry['fixtures'] ?? array() ) ),
		'cleanup_note'             => maa_adapter_smoke_keep_visual_acceptance_fixtures() ? 'Visual acceptance fixtures were retained for browser review.' : 'Smoke cleanup deletes fixtures unless MAA_ADAPTER_KEEP_VISUAL_ACCEPTANCE_FIXTURES=1 is set.',
		'required_browser_checks'  => array(
			'front_end_has_no_horizontal_overflow',
			'block_editor_has_no_invalid_block_recovery_prompt',
			'core_blocks_remain_individually_editable',
			'mobile_layout_wraps_or_stacks_without_clipping',
		),
	);
	$encoded = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	maa_adapter_smoke_assert( is_string( $encoded ) && false !== file_put_contents( $output_path, $encoded ), 'adapter smoke exported Gutenberg visual acceptance fixture manifest' );
}

/**
 * Registers an attachment fixture for deletion on every exit path.
 *
 * @param int $attachment_id Attachment post id.
 * @return void
 */
function maa_adapter_smoke_register_attachment_fixture( int $attachment_id ): void {
	if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
		return;
	}

	$registry =& maa_adapter_smoke_fixture_registry();
	$registry['attachment_ids'][] = $attachment_id;
	update_post_meta( $attachment_id, '_npcink_openclaw_adapter_smoke_fixture_run_id', maa_adapter_smoke_run_id() );
}

/**
 * Finds attachment fixtures that were tagged with the current smoke run id.
 *
 * @return int[]
 */
function maa_adapter_smoke_current_run_attachment_ids(): array {
	return array_values(
		array_unique(
			array_filter(
				array_map(
					'absint',
					get_posts(
						array(
							'fields'         => 'ids',
							'meta_key'       => '_npcink_openclaw_adapter_smoke_fixture_run_id',
							'meta_value'     => maa_adapter_smoke_run_id(),
							'post_status'    => 'inherit',
							'post_type'      => 'attachment',
							'posts_per_page' => -1,
						)
					)
				)
			)
		)
	);
}

/**
 * Finds smoke media leaks by reserved test prefixes in attachment title, slug,
 * guid, or attached file metadata.
 *
 * @return int[]
 */
function maa_adapter_smoke_known_media_fixture_leak_ids(): array {
	global $wpdb;

	$ids      = array();
	$prefixes = array(
		'adapter-rename-smoke-',
		'adapter-rename-dry-target-',
		'adapter-rename-commit-target-',
		'adapter-visual-smoke-',
		'codex-commit-',
	);

	foreach ( $prefixes as $prefix ) {
		$like = '%' . $wpdb->esc_like( $prefix ) . '%';
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm
					ON p.ID = pm.post_id
					AND pm.meta_key = '_wp_attached_file'
				WHERE p.post_type = 'attachment'
					AND (
						p.post_title LIKE %s
						OR p.post_name LIKE %s
						OR p.guid LIKE %s
						OR pm.meta_value LIKE %s
					)",
				$like,
				$like,
				$like,
				$like
			)
		);
		$ids  = array_merge( $ids, array_map( 'absint', is_array( $rows ) ? $rows : array() ) );
	}

	return array_values( array_unique( array_filter( $ids ) ) );
}

/**
 * Finds smoke media files by reserved test prefixes in uploads and backups.
 *
 * @return string[]
 */
function maa_adapter_smoke_known_media_fixture_file_paths(): array {
	$uploads = wp_upload_dir();
	$basedir = is_array( $uploads ) && ! empty( $uploads['basedir'] ) ? untrailingslashit( (string) $uploads['basedir'] ) : '';
	if ( '' === $basedir ) {
		return array();
	}

	$paths = array();
	foreach ( array( 'adapter-rename-*', 'adapter-visual-*', 'codex-commit-*' ) as $pattern ) {
		$paths = array_merge( $paths, (array) glob( $basedir . '/20[0-9][0-9]/*/' . $pattern ) );
		$paths = array_merge( $paths, (array) glob( $basedir . '/npcink-abilities-toolkit-backups/20[0-9][0-9]/*/' . $pattern ) );
	}

	return array_values( array_filter( array_unique( $paths ), 'is_file' ) );
}

/**
 * Returns file paths for retained visual acceptance attachments.
 *
 * @return string[]
 */
function maa_adapter_smoke_retained_visual_acceptance_file_paths(): array {
	if ( ! maa_adapter_smoke_keep_visual_acceptance_fixtures() ) {
		return array();
	}
	$visual_acceptance_registry = maa_adapter_smoke_visual_acceptance_registry();
	$paths = array();
	foreach ( array_unique( array_filter( array_map( 'absint', (array) ( $visual_acceptance_registry['attachment_ids'] ?? array() ) ) ) ) as $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( is_string( $file ) && '' !== $file ) {
			$paths[] = $file;
		}
	}
	return array_values( array_unique( $paths ) );
}

/**
 * Asserts no known smoke media fixture remains in the media library or uploads.
 *
 * @return void
 */
function maa_adapter_smoke_assert_no_media_fixture_leaks(): void {
	$visual_acceptance_registry = maa_adapter_smoke_visual_acceptance_registry();
	$allowed_retained_attachment_ids = maa_adapter_smoke_keep_visual_acceptance_fixtures()
		? array_values( array_unique( array_filter( array_map( 'absint', (array) ( $visual_acceptance_registry['attachment_ids'] ?? array() ) ) ) ) )
		: array();
	$leaks = array_values(
		array_unique(
			array_merge(
				maa_adapter_smoke_current_run_attachment_ids(),
				maa_adapter_smoke_known_media_fixture_leak_ids()
			)
		)
	);
	if ( ! empty( $allowed_retained_attachment_ids ) ) {
		$leaks = array_values( array_diff( $leaks, $allowed_retained_attachment_ids ) );
	}
	$file_paths = maa_adapter_smoke_known_media_fixture_file_paths();
	if ( ! empty( $allowed_retained_attachment_ids ) ) {
		$file_paths = array_values( array_diff( $file_paths, maa_adapter_smoke_retained_visual_acceptance_file_paths() ) );
	}

	maa_adapter_smoke_assert( empty( $leaks ) && empty( $file_paths ), 'adapter smoke leaves no registered or reserved-prefix media fixtures behind' );
}

/**
 * Cleans all registered smoke fixtures. This is intentionally idempotent
 * because it runs both on success and through register_shutdown_function().
 *
 * @return void
 */
function maa_adapter_smoke_cleanup_registered_fixtures(): void {
	$registry =& maa_adapter_smoke_fixture_registry();
	if ( true === (bool) ( $registry['cleaned'] ?? false ) ) {
		return;
	}

	$registry['cleaned'] = true;
	global $wpdb;

	$proposal_ids = array_values( array_unique( array_filter( array_map( 'strval', (array) ( $registry['proposal_ids'] ?? array() ) ) ) ) );
	if ( ! empty( $proposal_ids ) ) {
		$execution_records = get_option( 'npcink_openclaw_adapter_execution_records', array() );
		if ( is_array( $execution_records ) ) {
			foreach ( $proposal_ids as $cleanup_proposal_id ) {
				unset( $execution_records[ md5( $cleanup_proposal_id ) ] );
				foreach ( $execution_records as $record_key => $record ) {
					if ( is_array( $record ) && $cleanup_proposal_id === (string) ( $record['proposal_id'] ?? '' ) ) {
						unset( $execution_records[ $record_key ] );
					}
				}
			}
			update_option( 'npcink_openclaw_adapter_execution_records', $execution_records, false );
		}

		$preflight_handoffs = get_option( 'npcink_openclaw_adapter_preflight_handoffs', array() );
		if ( is_array( $preflight_handoffs ) ) {
			foreach ( $proposal_ids as $cleanup_proposal_id ) {
				unset( $preflight_handoffs[ md5( $cleanup_proposal_id ) ] );
				foreach ( $preflight_handoffs as $record_key => $record ) {
					if ( is_array( $record ) && $cleanup_proposal_id === (string) ( $record['proposal_id'] ?? '' ) ) {
						unset( $preflight_handoffs[ $record_key ] );
					}
				}
			}
			update_option( 'npcink_openclaw_adapter_preflight_handoffs', $preflight_handoffs, false );
		}

		foreach ( $proposal_ids as $cleanup_proposal_id ) {
			$wpdb->delete( $wpdb->prefix . 'npcink_governance_core_audit_log', array( 'proposal_id' => $cleanup_proposal_id ), array( '%s' ) );
			$wpdb->delete( $wpdb->prefix . 'npcink_governance_core_proposals', array( 'proposal_id' => $cleanup_proposal_id ), array( '%s' ) );
		}
	}

	$read_request_ids = array_values( array_unique( array_filter( array_map( 'strval', (array) ( $registry['read_request_ids'] ?? array() ) ) ) ) );
	if ( ! empty( $read_request_ids ) ) {
		foreach ( $read_request_ids as $cleanup_read_request_id ) {
			$wpdb->delete( $wpdb->prefix . 'npcink_governance_core_audit_log', array( 'proposal_id' => $cleanup_read_request_id ), array( '%s' ) );
			$wpdb->delete( $wpdb->prefix . 'npcink_governance_core_read_requests', array( 'request_id' => $cleanup_read_request_id ), array( '%s' ) );
		}
	}

	$core_app_ids = array_values( array_unique( array_filter( array_map( 'strval', (array) ( $registry['core_app_ids'] ?? array() ) ) ) ) );
	$core_app_key_ids = array_values( array_unique( array_filter( array_map( 'strval', (array) ( $registry['core_app_key_ids'] ?? array() ) ) ) ) );
	if ( ! empty( $core_app_ids ) || ! empty( $core_app_key_ids ) ) {
		$audit_table = $wpdb->prefix . 'npcink_governance_core_audit_log';
		$app_table   = $wpdb->prefix . 'npcink_governance_core_app_keys';
		$rate_table  = $wpdb->prefix . 'npcink_governance_core_app_rate_limits';
		foreach ( $core_app_ids as $core_app_id ) {
			$wpdb->delete( $rate_table, array( 'app_id' => sanitize_text_field( $core_app_id ) ), array( '%s' ) );
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM ' . $audit_table . ' WHERE metadata_json LIKE %s',
					'%' . $wpdb->esc_like( '"app_id":"' . $core_app_id . '"' ) . '%'
				)
			);
		}
		foreach ( $core_app_key_ids as $core_app_key_id ) {
			$wpdb->delete( $rate_table, array( 'key_id' => sanitize_text_field( $core_app_key_id ) ), array( '%s' ) );
			$wpdb->delete( $app_table, array( 'key_id' => sanitize_text_field( $core_app_key_id ) ), array( '%s' ) );
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM ' . $audit_table . ' WHERE metadata_json LIKE %s',
					'%' . $wpdb->esc_like( '"key_id":"' . $core_app_key_id . '"' ) . '%'
				)
			);
		}
	}

	$visual_acceptance_registry = maa_adapter_smoke_visual_acceptance_registry();
	$visual_acceptance_attachment_ids = maa_adapter_smoke_keep_visual_acceptance_fixtures()
		? array_values( array_unique( array_filter( array_map( 'absint', (array) ( $visual_acceptance_registry['attachment_ids'] ?? array() ) ) ) ) )
		: array();
	$visual_acceptance_post_ids = maa_adapter_smoke_keep_visual_acceptance_fixtures()
		? array_values( array_unique( array_filter( array_map( 'absint', (array) ( $visual_acceptance_registry['post_ids'] ?? array() ) ) ) ) )
		: array();

	$attachment_ids = array_values(
		array_unique(
			array_filter(
				array_merge(
					array_map( 'absint', (array) ( $registry['attachment_ids'] ?? array() ) ),
					maa_adapter_smoke_current_run_attachment_ids(),
					maa_adapter_smoke_known_media_fixture_leak_ids()
				)
			)
		)
	);
	if ( ! empty( $visual_acceptance_attachment_ids ) ) {
		$attachment_ids = array_values( array_diff( $attachment_ids, $visual_acceptance_attachment_ids ) );
	}
	foreach ( $attachment_ids as $cleanup_attachment_id ) {
		wp_delete_attachment( (int) $cleanup_attachment_id, true );
	}
	$retained_visual_acceptance_file_paths = maa_adapter_smoke_retained_visual_acceptance_file_paths();
	foreach ( maa_adapter_smoke_known_media_fixture_file_paths() as $fixture_file_path ) {
		if ( in_array( $fixture_file_path, $retained_visual_acceptance_file_paths, true ) ) {
			continue;
		}
		@unlink( $fixture_file_path );
	}
	foreach ( array_values( array_unique( array_filter( array_map( 'absint', (array) ( $registry['comment_ids'] ?? array() ) ) ) ) ) as $cleanup_comment_id ) {
		wp_delete_comment( (int) $cleanup_comment_id, true );
	}
	$cleanup_post_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $registry['post_ids'] ?? array() ) ) ) ) );
	if ( ! empty( $visual_acceptance_post_ids ) ) {
		$cleanup_post_ids = array_values( array_diff( $cleanup_post_ids, $visual_acceptance_post_ids ) );
	}
	foreach ( $cleanup_post_ids as $cleanup_post_id ) {
		wp_delete_post( (int) $cleanup_post_id, true );
	}
	foreach ( (array) ( $registry['terms'] ?? array() ) as $cleanup_term ) {
		if ( is_array( $cleanup_term ) ) {
			wp_delete_term( (int) ( $cleanup_term['term_id'] ?? 0 ), (string) ( $cleanup_term['taxonomy'] ?? '' ) );
		}
	}
}

/**
 * Creates an unattached image attachment with missing metadata for media plan smoke.
 *
 * @return int
 */
function maa_adapter_smoke_create_media_plan_attachment(): int {
	$uploads = wp_upload_dir();
	$url     = is_array( $uploads ) && ! empty( $uploads['baseurl'] ) ? trailingslashit( (string) $uploads['baseurl'] ) . 'adapter-media-plan-smoke.jpg' : '';
	$id      = wp_insert_attachment(
		array(
			'post_title'     => 'Adapter Media Plan Smoke',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/jpeg',
			'post_parent'    => 0,
			'post_excerpt'   => '',
			'post_content'   => '',
			'guid'           => $url,
		),
		'',
		0,
		true
	);

	maa_adapter_smoke_assert( ! is_wp_error( $id ) && (int) $id > 0, 'adapter smoke created media fixture attachment' );
	maa_adapter_smoke_register_attachment_fixture( (int) $id );
	update_post_meta( (int) $id, '_wp_attachment_image_alt', '' );
	return (int) $id;
}

/**
 * Creates a real uploaded PNG attachment for media and browser smoke checks.
 *
 * @param string $file_prefix Reserved fixture file prefix.
 * @return int
 */
function maa_adapter_smoke_create_real_media_attachment( string $file_prefix = 'adapter-rename-smoke-' ): int {
	$uploads = wp_upload_dir();
	$basedir = is_array( $uploads ) ? (string) ( $uploads['basedir'] ?? '' ) : '';
	$baseurl = is_array( $uploads ) ? (string) ( $uploads['baseurl'] ?? '' ) : '';
	$subdir  = is_array( $uploads ) ? (string) ( $uploads['subdir'] ?? '' ) : '';
	maa_adapter_smoke_assert( '' !== $basedir && '' !== $baseurl, 'adapter smoke found uploads directory for real media fixture' );

	$target_dir = untrailingslashit( $basedir ) . $subdir;
	maa_adapter_smoke_assert( wp_mkdir_p( $target_dir ), 'adapter smoke created uploads directory for real media fixture' );

	$file_prefix = preg_replace( '/[^A-Za-z0-9_-]/', '', $file_prefix );
	$file_prefix = is_string( $file_prefix ) && '' !== $file_prefix ? $file_prefix : 'adapter-rename-smoke-';
	$file_name = wp_unique_filename( $target_dir, $file_prefix . substr( wp_generate_uuid4(), 0, 8 ) . '.png' );
	$file_path = trailingslashit( $target_dir ) . $file_name;
	$bytes     = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true );
	maa_adapter_smoke_assert( is_string( $bytes ) && '' !== $bytes, 'adapter smoke decoded real media fixture bytes' );
	maa_adapter_smoke_assert( false !== file_put_contents( $file_path, $bytes ), 'adapter smoke wrote real media fixture file' );

	$relative_file = ltrim( trailingslashit( trim( $subdir, '/' ) ) . $file_name, '/' );
	$url           = trailingslashit( untrailingslashit( $baseurl ) . $subdir ) . $file_name;
	$id            = wp_insert_attachment(
		array(
			'post_title'     => 'Adapter Rename Media Smoke',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/png',
			'post_parent'    => 0,
			'post_excerpt'   => '',
			'post_content'   => '',
			'guid'           => $url,
		),
		$file_path,
		0,
		true
	);

	if ( is_wp_error( $id ) || (int) $id <= 0 ) {
		@unlink( $file_path );
	}
	maa_adapter_smoke_assert( ! is_wp_error( $id ) && (int) $id > 0, 'adapter smoke created real media fixture attachment' );
	maa_adapter_smoke_register_attachment_fixture( (int) $id );
	update_post_meta( (int) $id, '_wp_attached_file', $relative_file );
	wp_update_attachment_metadata(
		(int) $id,
		array(
			'width'  => 1,
			'height' => 1,
			'file'   => $relative_file,
			'sizes'  => array(),
		)
	);

	return (int) $id;
}

/**
 * Builds a local Cloud derivative payload for media optimization smoke tests.
 *
 * @param int    $attachment_id Attachment id.
 * @param string $artifact_id Artifact id.
 * @param string $artifact_contents Downloaded artifact bytes.
 * @param bool   $with_media_details Whether to include reviewed metadata input.
 * @param bool   $with_content_reference_repairs Whether to include content reference repair preview.
 * @return array<string,mixed>
 */
function maa_adapter_smoke_media_optimization_payload_params( int $attachment_id, string $artifact_id, string $artifact_contents, bool $with_media_details = true, bool $with_content_reference_repairs = false ): array {
	$sha256       = hash( 'sha256', $artifact_contents );
	$expires_at   = gmdate( 'c', time() + 600 );
	$current_mime = (string) get_post_mime_type( $attachment_id );
	if ( '' === $current_mime ) {
		$current_mime = 'image/png';
	}
	$uploads       = wp_upload_dir();
	$relative_file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
	$current_file  = '' !== $relative_file && is_array( $uploads )
		? trailingslashit( (string) ( $uploads['basedir'] ?? '' ) ) . ltrim( $relative_file, '/' )
		: '';
	$current_size  = '' !== $current_file && is_readable( $current_file ) ? filesize( $current_file ) : 0;

	$GLOBALS['maa_adapter_smoke_cloud_artifact_downloads'][ $artifact_id ] = array(
		'artifact_id'    => $artifact_id,
		'contents'       => $artifact_contents,
		'mime_type'      => 'image/webp',
		'filesize_bytes' => strlen( $artifact_contents ),
		'sha256'         => $sha256,
		'expires_at'     => $expires_at,
	);

	$params = array(
		'ability_response'    => array(
			'success' => true,
			'data'    => array(
				'request_contract_version' => 'media_derivative_cloud_request.v1',
				'attachment_id'            => $attachment_id,
				'readonly'                 => true,
				'proposal_only'            => true,
				'cloud_job_payload'        => array(
					'job_type'             => 'generate_optimized_media_derivative',
					'source_asset'         => array(
						'mime_type'      => $current_mime,
						'width'          => 1,
						'height'         => 1,
						'filesize_bytes' => absint( $current_size ),
					),
					'requested_derivative' => array(
						'format'           => 'webp',
						'quality'          => 82,
						'replace_original' => false,
					),
					'warnings'             => array(),
				),
				'local_adoption'           => array(
					'final_write_owner'             => 'local_wordpress_host',
					'wordpress_write_included'      => false,
					'attachment_metadata_write_included' => false,
				),
			),
		),
		'cloud_result'        => array(
			'status'     => 'succeeded',
			'run_id'     => 'adapter-smoke-derivative-run',
			'derivative' => array(
				'artifact_id'    => $artifact_id,
				'mime_type'      => 'image/webp',
				'format'         => 'webp',
				'width'          => 1,
				'height'         => 1,
				'filesize_bytes' => strlen( $artifact_contents ),
				'sha256'         => $sha256,
				'checksum'       => 'sha256:' . $sha256,
			),
		),
		'derivative_artifact' => array(
			'attachment_id'   => $attachment_id,
			'artifact_id'     => $artifact_id,
			'run_id'          => 'adapter-smoke-derivative-run',
			'mime_type'       => 'image/webp',
			'format'          => 'webp',
			'width'           => 1,
			'height'          => 1,
			'filesize_bytes'  => strlen( $artifact_contents ),
			'expires_at'      => $expires_at,
			'download_url'    => 'https://example.test/' . rawurlencode( $artifact_id ) . '.webp',
			'sha256'          => $sha256,
			'checksum'        => 'sha256:' . $sha256,
		),
	);

	if ( $with_media_details ) {
		$params['media_details_input'] = array(
			'title'       => 'Adapter media optimization smoke',
			'alt'         => 'Adapter media optimization smoke image',
			'caption'     => 'Adapter media optimization smoke image.',
			'description' => 'Reviewed metadata for the adapter media optimization smoke.',
			'source_type' => 'ai_generated',
		);
	}
	if ( $with_content_reference_repairs ) {
		$params['ability_response']['data']['content_reference_repairs_preview'] = array(
			'attachment_id'      => $attachment_id,
			'applied'            => false,
			'scanned_count'      => 1,
			'post_count'         => 1,
			'replacement_count'  => 1,
			'reference_strategy' => 'replace_old_main_and_sized_upload_urls_with_new_main_file_url',
			'repairs'            => array(
				array(
					'post_id'         => 4312,
					'post_type'       => 'post',
					'post_status'     => 'publish',
					'title'           => 'Adapter smoke reference repair',
					'operation_count' => 1,
					'operations'      => array(
						array(
							'op'      => 'replace',
							'find'    => 'https://example.test/wp-content/uploads/2026/06/original.jpg',
							'replace' => 'https://example.test/wp-content/uploads/2026/06/optimized.webp',
							'limit'   => 1,
						),
					),
				),
			),
		);
	}

	return $params;
}

/**
 * Creates a post fixture for approved proposal execution smoke.
 *
 * @return int
 */
function maa_adapter_smoke_create_trash_post_fixture(): int {
	$post_id = wp_insert_post(
		array(
			'post_title'   => 'Adapter Approved Trash Smoke',
			'post_content' => 'Adapter approved proposal execution smoke.',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		),
		true
	);

	maa_adapter_smoke_assert( ! is_wp_error( $post_id ) && (int) $post_id > 0, 'adapter smoke created trash-post fixture' );
	$registry =& maa_adapter_smoke_fixture_registry();
	$registry['post_ids'][] = (int) $post_id;
	return (int) $post_id;
}

/**
 * Creates a comment fixture for comment execution smoke.
 *
 * @param int        $post_id Post id.
 * @param string     $content Comment content.
 * @param int|string $approved Comment approval state.
 * @return int
 */
function maa_adapter_smoke_create_comment_fixture(
	int $post_id,
	string $content = 'Adapter reply-comment parent smoke.',
	$approved = 1
): int {
	$comment_id = wp_insert_comment(
		array(
			'comment_post_ID'      => $post_id,
			'comment_content'      => $content,
			'comment_approved'     => $approved,
			'comment_author'       => 'Adapter Smoke',
			'comment_author_email' => 'adapter-smoke@example.test',
		)
	);

	maa_adapter_smoke_assert( (int) $comment_id > 0, 'adapter smoke created comment fixture' );
	$registry =& maa_adapter_smoke_fixture_registry();
	$registry['comment_ids'][] = (int) $comment_id;
	return (int) $comment_id;
}

$maa_adapter_smoke_fixture_registry =& maa_adapter_smoke_fixture_registry();
$maa_adapter_smoke_cleanup_proposal_ids =& $maa_adapter_smoke_fixture_registry['proposal_ids'];
$maa_adapter_smoke_cleanup_read_request_ids =& $maa_adapter_smoke_fixture_registry['read_request_ids'];
$maa_adapter_smoke_cleanup_attachment_ids =& $maa_adapter_smoke_fixture_registry['attachment_ids'];
$maa_adapter_smoke_cleanup_post_ids =& $maa_adapter_smoke_fixture_registry['post_ids'];
$maa_adapter_smoke_cleanup_comment_ids =& $maa_adapter_smoke_fixture_registry['comment_ids'];
$maa_adapter_smoke_cleanup_terms =& $maa_adapter_smoke_fixture_registry['terms'];
register_shutdown_function( 'maa_adapter_smoke_cleanup_registered_fixtures' );

$health = maa_adapter_smoke_rest( 'GET', '/npcink-openclaw-adapter/v1/health' );
$health_dispatch_event = maa_adapter_smoke_observability_event( 'adapter.openclaw.dispatch.completed', 'ok', '/npcink-openclaw-adapter/v1/health' );
maa_adapter_smoke_assert( ! empty( $health_dispatch_event ), 'adapter emits OpenClaw dispatch completed event for health' );
maa_adapter_smoke_assert( 'GET' === (string) ( $health_dispatch_event['method'] ?? '' ), 'adapter health dispatch event carries method' );
maa_adapter_smoke_assert( 200 === (int) ( $health_dispatch_event['status_code'] ?? 0 ), 'adapter health dispatch event carries status code' );
maa_adapter_smoke_assert_observability_safe( $health_dispatch_event, 'adapter health dispatch event' );
maa_adapter_smoke_assert( true === (bool) ( $health['core_capabilities'] ?? false ), 'adapter sees Core capabilities route' );
maa_adapter_smoke_assert( true === (bool) ( $health['abilities_catalog'] ?? false ), 'adapter sees WordPress Abilities catalog route' );
maa_adapter_smoke_assert( false === (bool) ( $health['core_proxy_execute'] ?? true ), 'adapter keeps Core proxy execution disabled' );
maa_adapter_smoke_assert( false === (bool) ( $health['commit_execution'] ?? true ), 'adapter keeps Core commit execution disabled' );
maa_adapter_smoke_assert( 'npcink_governance_core_admin' === (string) ( $health['approval_surface'] ?? '' ), 'adapter health exposes Core admin approval surface' );
maa_adapter_smoke_assert( array_key_exists( 'core_app_token_configured', $health ), 'adapter health exposes Core app token configured state without token value' );
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_client_policy.v1' === (string) ( $health['client_policy']['schema_version'] ?? '' ), 'adapter health exposes machine-readable client policy' );
maa_adapter_smoke_assert( '1' === (string) ( $health['client_policy']['policy_version'] ?? '' ), 'adapter health exposes client policy version' );
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_contract.v1' === (string) ( $health['contract']['schema_version'] ?? '' ), 'adapter health exposes contract metadata' );
maa_adapter_smoke_assert( '2' === (string) ( $health['contract']['adapter_contract_version'] ?? '' ), 'adapter health exposes adapter contract version' );
maa_adapter_smoke_assert( 0 === strpos( (string) ( $health['contract']['execution_profile_registry_hash'] ?? '' ), 'sha256:' ), 'adapter health exposes execution profile registry hash' );
maa_adapter_smoke_assert( 0 === strpos( (string) ( $health['contract']['supported_plan_ability_ids_hash'] ?? '' ), 'sha256:' ), 'adapter health exposes supported plan ability hash' );
maa_adapter_smoke_assert_contract_snapshot( $health, 'adapter health' );
maa_adapter_smoke_assert( true === (bool) ( $health['dependency_contracts_ready'] ?? false ), 'adapter health reports dependency contracts ready' );
maa_adapter_smoke_assert( 'npcink_governance_core_contract.v1' === (string) ( $health['dependency_contracts']['npcink-governance-core']['schema_version'] ?? '' ), 'adapter health detects Core contract schema' );
maa_adapter_smoke_assert( false === (bool) ( $health['dependency_contracts']['npcink-governance-core']['core_proxy_execute'] ?? true ), 'adapter health detects Core proxy execution disabled' );
maa_adapter_smoke_assert( false === (bool) ( $health['dependency_contracts']['npcink-governance-core']['commit_execution'] ?? true ), 'adapter health detects Core commit execution disabled' );
maa_adapter_smoke_assert( false === (bool) ( $health['dependency_contracts']['npcink-governance-core']['provider_secret_storage'] ?? true ), 'adapter health detects Core provider secret storage disabled' );
maa_adapter_smoke_assert( true === (bool) ( $health['dependency_contracts']['npcink-governance-core']['core_boundary_supported'] ?? false ), 'adapter health detects supported Core execution boundary' );
maa_adapter_smoke_assert( true === (bool) ( $health['dependency_contracts']['npcink-governance-core']['site_binding'] ?? false ), 'adapter health detects Core site context binding' );
maa_adapter_smoke_assert( true === (bool) ( $health['dependency_contracts']['npcink-governance-core']['signed_client_fingerprint_binding'] ?? false ), 'adapter health detects Core signed client fingerprint binding' );
maa_adapter_smoke_assert( 'npcink_abilities_toolkit_contract.v1' === (string) ( $health['dependency_contracts']['npcink-abilities-toolkit']['schema_version'] ?? '' ), 'adapter health detects Toolkit contract schema' );
maa_adapter_smoke_assert( true === (bool) ( $health['dependency_contracts']['npcink-abilities-toolkit']['host_governed_writes'] ?? false ), 'adapter health detects Toolkit host-governed writes' );
maa_adapter_smoke_assert( false === (bool) ( $health['dependency_contracts']['npcink-abilities-toolkit']['commit_default'] ?? true ), 'adapter health detects Toolkit commit default disabled' );
maa_adapter_smoke_assert( in_array( 'profile_path', (array) ( $health['client_policy']['forbidden_outputs'] ?? array() ), true ), 'adapter health policy forbids profile path output' );
maa_adapter_smoke_assert( in_array( 'key_id', (array) ( $health['client_policy']['forbidden_outputs'] ?? array() ), true ), 'adapter health policy forbids key id output' );
maa_adapter_smoke_assert( in_array( 'database_direct', (array) ( $health['client_policy']['forbidden_local_access'] ?? array() ), true ), 'adapter health policy forbids direct database access' );
maa_adapter_smoke_assert( true === (bool) ( $health['client_policy']['sensitive_read_flow']['required'] ?? false ), 'adapter health policy requires sensitive read flow' );
maa_adapter_smoke_assert( 'ability_id_plus_input_hash' === (string) ( $health['client_policy']['sensitive_read_flow']['grant_binding'] ?? '' ), 'adapter health policy documents read grant binding' );
maa_adapter_smoke_assert( in_array( 'read_requests:create', (array) ( $health['core_app_token_required_scopes'] ?? array() ), true ), 'adapter health documents Core read request create scope' );
maa_adapter_smoke_assert( in_array( 'read_requests:read', (array) ( $health['core_app_token_required_scopes'] ?? array() ), true ), 'adapter health documents Core read request status scope' );
maa_adapter_smoke_assert( in_array( 'read_requests:preflight', (array) ( $health['core_app_token_required_scopes'] ?? array() ), true ), 'adapter health documents Core read request preflight scope' );
maa_adapter_smoke_assert( 'POST /read-requests' === (string) ( $health['sensitive_read_authorization']['request_route'] ?? '' ), 'adapter health exposes sensitive read request route' );
maa_adapter_smoke_assert( 'GET /read-requests/{request_id}' === (string) ( $health['sensitive_read_authorization']['status_route'] ?? '' ), 'adapter health exposes sensitive read status route' );
maa_adapter_smoke_assert( 'POST /run-read-ability with read_request_id' === (string) ( $health['sensitive_read_authorization']['execution_route'] ?? '' ), 'adapter health exposes sensitive read execution route' );
maa_adapter_smoke_assert( ! isset( $health['cloud_addon'] ), 'adapter health does not expose Cloud Addon runtime detail' );
maa_adapter_smoke_assert( in_array( 'GET /proposals/{proposal_id}/media-optimization-readiness', (array) ( $health['proposal_status_routes'] ?? array() ), true ), 'adapter health points to proposal-specific media readiness route' );
maa_adapter_smoke_assert( ! isset( $health['read_shortcuts'] ), 'adapter health does not expose expanded read shortcut routes' );
maa_adapter_smoke_assert( ! isset( $health['diagnostics'] ), 'adapter health does not expose diagnostic shortcut defaults' );
maa_adapter_smoke_assert( in_array( 'adapter_request_id', (array) ( $health['ai_request_log_context_fields'] ?? array() ), true ), 'adapter health exposes provider log adapter request id context' );
maa_adapter_smoke_assert( ! in_array( 'ai_provider', (array) ( $health['ai_request_log_context_fields'] ?? array() ), true ), 'adapter health does not expose provider routing context field' );
maa_adapter_smoke_assert( ! in_array( 'ai_model', (array) ( $health['ai_request_log_context_fields'] ?? array() ), true ), 'adapter health does not expose model routing context field' );
maa_adapter_smoke_assert( in_array( 'npcink_governance_core.correlation_id', (array) ( $health['ai_request_log_context_fields'] ?? array() ), true ), 'adapter health exposes nested Core correlation context field' );
maa_adapter_smoke_assert( 'wordpress_rest_application_password' === (string) ( $health['auth']['type'] ?? '' ), 'adapter health exposes Application Password auth handoff' );
maa_adapter_smoke_assert( 'proposals:read' === (string) ( $health['supported_guidance']['proposal_status']['core_required_scope'] ?? '' ), 'adapter health documents proposal status read scope' );
maa_adapter_smoke_assert( in_array( 'GET /proposals/{proposal_id}', (array) ( $health['proposal_status_routes'] ?? array() ), true ), 'adapter health exposes proposal status routes' );
maa_adapter_smoke_assert( in_array( 'GET /proposals/{proposal_id}/media-optimization-readiness', (array) ( $health['proposal_status_routes'] ?? array() ), true ), 'adapter health exposes media optimization readiness status route' );
maa_adapter_smoke_assert( in_array( 'POST /proposals/from-plan', (array) ( $health['plan_proposal_routes'] ?? array() ), true ), 'adapter health exposes plan-to-proposal route' );
maa_adapter_smoke_assert( in_array( 'npcink-toolbox/build-article-batch-write-plan', (array) ( $health['supported_plan_ability_ids'] ?? array() ), true ), 'adapter health exposes article batch plan supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-toolbox/build-site-knowledge-review-plan', (array) ( $health['supported_plan_ability_ids'] ?? array() ), true ), 'adapter health exposes Site Knowledge review plan supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/build-media-optimization-plan', (array) ( $health['supported_plan_ability_ids'] ?? array() ), true ), 'adapter health exposes media optimization plan supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/build-media-adoption-enhancement-plan', (array) ( $health['supported_plan_ability_ids'] ?? array() ), true ), 'adapter health exposes media adoption enhancement plan supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/build-media-rename-plan', (array) ( $health['supported_plan_ability_ids'] ?? array() ), true ), 'adapter health exposes media rename plan supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/build-content-metadata-apply-plan', (array) ( $health['supported_plan_ability_ids'] ?? array() ), true ), 'adapter health exposes content metadata apply plan supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/build-article-optimization-apply-plan', (array) ( $health['supported_plan_ability_ids'] ?? array() ), true ), 'adapter health exposes article optimization apply plan supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/build-article-block-plan', (array) ( $health['supported_plan_ability_ids'] ?? array() ), true ), 'adapter health exposes article block plan supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/build-block-theme-site-plan', (array) ( $health['supported_plan_ability_ids'] ?? array() ), true ), 'adapter health exposes block theme site plan supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/build-pattern-page-plan', (array) ( $health['supported_plan_ability_ids'] ?? array() ), true ), 'adapter health exposes pattern page plan supported profiles' );
maa_adapter_smoke_assert( in_array( 'POST /proposals/{proposal_id}/execute', (array) ( $health['approved_proposal_execution_routes'] ?? array() ), true ), 'adapter health exposes approved proposal execution route' );
maa_adapter_smoke_assert( in_array( 'POST /proposals/{proposal_id}/approve-and-execute', (array) ( $health['approved_proposal_execution_routes'] ?? array() ), true ), 'adapter health exposes approve-and-execute route' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/trash-post', (array) ( $health['supported_execute_ability_ids'] ?? array() ), true ), 'adapter health exposes trash-post execute supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/create-draft', (array) ( $health['supported_execute_ability_ids'] ?? array() ), true ), 'adapter health exposes create-draft execute supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/update-post', (array) ( $health['supported_execute_ability_ids'] ?? array() ), true ), 'adapter health exposes update-post execute supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/update-template-blocks', (array) ( $health['supported_execute_ability_ids'] ?? array() ), true ), 'adapter health exposes update-template-blocks execute supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/upsert-template-blocks', (array) ( $health['supported_execute_ability_ids'] ?? array() ), true ), 'adapter health exposes upsert-template-blocks execute supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/update-template-part-blocks', (array) ( $health['supported_execute_ability_ids'] ?? array() ), true ), 'adapter health exposes update-template-part-blocks execute supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/patch-setting-value', (array) ( $health['supported_execute_ability_ids'] ?? array() ), true ), 'adapter health exposes patch-setting-value execute supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/set-post-seo-meta', (array) ( $health['supported_execute_ability_ids'] ?? array() ), true ), 'adapter health exposes set-post-seo-meta execute supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/set-post-slug', (array) ( $health['supported_execute_ability_ids'] ?? array() ), true ), 'adapter health exposes set-post-slug execute supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/set-post-terms', (array) ( $health['supported_execute_ability_ids'] ?? array() ), true ), 'adapter health exposes set-post-terms execute supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/delete-term', (array) ( $health['supported_execute_ability_ids'] ?? array() ), true ), 'adapter health exposes delete-term execute supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/update-media-details', (array) ( $health['supported_execute_ability_ids'] ?? array() ), true ), 'adapter health exposes update-media-details execute supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/optimize-media-asset', (array) ( $health['supported_execute_ability_ids'] ?? array() ), true ), 'adapter health exposes optimize-media-asset execute supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/replace-media-file', (array) ( $health['supported_execute_ability_ids'] ?? array() ), true ), 'adapter health exposes replace-media-file execute supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/restore-media-backup', (array) ( $health['supported_execute_ability_ids'] ?? array() ), true ), 'adapter health exposes restore-media-backup execute supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/adopt-cloud-media-derivative', (array) ( $health['supported_execute_ability_ids'] ?? array() ), true ), 'adapter health exposes adopt-cloud-media-derivative execute supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/rename-media-file', (array) ( $health['supported_execute_ability_ids'] ?? array() ), true ), 'adapter health exposes rename-media-file execute supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/delete-media-permanently', (array) ( $health['supported_execute_ability_ids'] ?? array() ), true ), 'adapter health exposes delete-media-permanently execute supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/reply-comment', (array) ( $health['supported_execute_ability_ids'] ?? array() ), true ), 'adapter health exposes reply-comment execute supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/trash-comment', (array) ( $health['supported_execute_ability_ids'] ?? array() ), true ), 'adapter health exposes trash-comment execute supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/approve-comment', (array) ( $health['supported_execute_ability_ids'] ?? array() ), true ), 'adapter health exposes approve-comment execute supported profiles' );

$help = maa_adapter_smoke_rest( 'GET', '/npcink-openclaw-adapter/v1/help' );
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_client_policy.v1' === (string) ( $help['client_policy']['schema_version'] ?? '' ), 'adapter help exposes machine-readable client policy' );
maa_adapter_smoke_assert( false === (bool) ( $help['client_policy']['allowed_transport']['direct_database_access_allowed'] ?? true ), 'adapter help policy forbids direct database access' );
maa_adapter_smoke_assert( 'POST /read-requests' === (string) ( $help['client_policy']['sensitive_read_flow']['steps']['create'] ?? '' ), 'adapter help policy documents sensitive read request creation' );
$manifest = maa_adapter_smoke_rest( 'GET', '/npcink-openclaw-adapter/v1/connection/manifest' );
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_client_policy.v1' === (string) ( $manifest['client_policy']['schema_version'] ?? '' ), 'adapter connection manifest exposes machine-readable client policy' );
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_contract.v1' === (string) ( $manifest['contract']['schema_version'] ?? '' ), 'adapter connection manifest exposes contract metadata' );
maa_adapter_smoke_assert( 0 === strpos( (string) ( $manifest['contract']['supported_execute_ability_ids_hash'] ?? '' ), 'sha256:' ), 'adapter connection manifest exposes execution ability hash' );
maa_adapter_smoke_assert_contract_snapshot( $manifest, 'adapter connection manifest' );
maa_adapter_smoke_assert( $health['contract'] === $manifest['contract'], 'adapter connection manifest contract snapshot matches health' );
maa_adapter_smoke_assert( true === (bool) ( $manifest['dependency_contracts']['ready'] ?? false ), 'adapter connection manifest includes ready dependency contracts' );
maa_adapter_smoke_assert( in_array( 'custom_scripts_for_wordpress_data', (array) ( $manifest['client_policy']['forbidden_local_access'] ?? array() ), true ), 'adapter manifest policy forbids custom data scripts' );
maa_adapter_smoke_assert( true === (bool) ( $manifest['client_policy']['allowed_transport']['adapter_relative_routes_only'] ?? false ), 'adapter manifest policy requires adapter-relative routes' );
maa_adapter_smoke_assert( maa_adapter_smoke_help_has_route( $help, 'GET', '/proposals' ), 'adapter help exposes proposal list route' );
maa_adapter_smoke_assert( maa_adapter_smoke_help_has_route( $help, 'GET', '/proposals/{proposal_id}' ), 'adapter help exposes proposal detail route' );
maa_adapter_smoke_assert( maa_adapter_smoke_help_has_route( $help, 'GET', '/proposals/{proposal_id}/media-optimization-readiness' ), 'adapter help exposes media optimization readiness route' );
maa_adapter_smoke_assert( maa_adapter_smoke_help_has_route( $help, 'POST', '/proposals/from-plan' ), 'adapter help exposes plan-to-proposal route' );
maa_adapter_smoke_assert( maa_adapter_smoke_help_has_route( $help, 'POST', '/execute-approved-proposal' ), 'adapter help exposes execute-approved-proposal route' );
maa_adapter_smoke_assert( maa_adapter_smoke_help_has_route( $help, 'POST', '/proposals/{proposal_id}/execute' ), 'adapter help exposes proposal execute route' );
maa_adapter_smoke_assert( maa_adapter_smoke_help_has_route( $help, 'POST', '/proposals/{proposal_id}/approve-and-execute' ), 'adapter help exposes proposal approve-and-execute route' );
maa_adapter_smoke_assert( ! maa_adapter_smoke_help_has_route( $help, 'POST', '/proposals/{proposal_id}/approve' ), 'adapter help does not expose standalone approval route' );
maa_adapter_smoke_assert( ! maa_adapter_smoke_help_has_route( $help, 'POST', '/proposals/{proposal_id}/reject' ), 'adapter help does not expose standalone rejection route' );
maa_adapter_smoke_assert( ! maa_adapter_smoke_help_has_route( $help, 'POST', '/ai-provider-log-correlation-smoke' ), 'adapter help does not expose provider log correlation smoke route' );
maa_adapter_smoke_assert( maa_adapter_smoke_help_has_route( $help, 'POST', '/read-requests' ), 'adapter help exposes sensitive read request route' );
maa_adapter_smoke_assert( maa_adapter_smoke_help_has_route( $help, 'GET', '/read-requests' ), 'adapter help exposes sensitive read request list route' );
maa_adapter_smoke_assert( maa_adapter_smoke_help_has_route( $help, 'GET', '/read-requests/{request_id}' ), 'adapter help exposes sensitive read request status route' );
maa_adapter_smoke_assert( ! maa_adapter_smoke_help_has_route( $help, 'POST', '/media-metadata-optimization' ), 'adapter help does not expose media metadata optimization shortcut route' );
maa_adapter_smoke_assert( ! maa_adapter_smoke_help_has_route( $help, 'GET', '/plugin-conflict-diagnostics' ), 'adapter help does not expose plugin conflict diagnostic shortcut route' );
maa_adapter_smoke_assert( ! maa_adapter_smoke_help_has_route( $help, 'GET', '/term' ), 'adapter help does not expose term detail shortcut route' );
maa_adapter_smoke_assert( ! maa_adapter_smoke_help_has_route( $help, 'GET', '/article-writing-pack' ), 'adapter help does not expose AI article writing pack shortcut route' );
maa_adapter_smoke_assert( in_array( 'npcink-toolbox/build-article-batch-write-plan', (array) ( $help['supported_plan_ability_ids'] ?? array() ), true ), 'adapter help exposes article batch plan supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-toolbox/build-site-knowledge-review-plan', (array) ( $help['supported_plan_ability_ids'] ?? array() ), true ), 'adapter help exposes Site Knowledge review plan supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/build-media-adoption-enhancement-plan', (array) ( $help['supported_plan_ability_ids'] ?? array() ), true ), 'adapter help exposes media adoption enhancement plan supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/build-article-optimization-apply-plan', (array) ( $help['supported_plan_ability_ids'] ?? array() ), true ), 'adapter help exposes article optimization apply plan supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/build-article-block-plan', (array) ( $help['supported_plan_ability_ids'] ?? array() ), true ), 'adapter help exposes article block plan supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/build-block-theme-site-plan', (array) ( $help['supported_plan_ability_ids'] ?? array() ), true ), 'adapter help exposes block theme site plan supported profiles' );
maa_adapter_smoke_assert( in_array( 'npcink-abilities-toolkit/build-pattern-page-plan', (array) ( $help['supported_plan_ability_ids'] ?? array() ), true ), 'adapter help exposes pattern page plan supported profiles' );
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_contract.v1' === (string) ( $help['contract']['schema_version'] ?? '' ), 'adapter help exposes contract metadata' );
maa_adapter_smoke_assert( 0 === strpos( (string) ( $help['contract']['execution_profile_registry_hash'] ?? '' ), 'sha256:' ), 'adapter help exposes execution profile registry hash' );
maa_adapter_smoke_assert_contract_snapshot( $help, 'adapter help' );
maa_adapter_smoke_assert( $health['contract'] === $help['contract'], 'adapter help contract snapshot matches health' );
maa_adapter_smoke_assert( true === (bool) ( $help['dependency_contracts']['ready'] ?? false ), 'adapter help includes ready dependency contracts' );
maa_adapter_smoke_assert( ! isset( $help['openclaw_recipes'] ), 'adapter help does not expose OpenClaw recipe catalog' );
maa_adapter_smoke_assert_content_intent_fails_closed( 'Change the navigation menu and add a Products link.', 'adapter content intent navigation negative case', 'navigation_write_not_supported' );
maa_adapter_smoke_assert_content_intent_fails_closed( 'Change global styles and write a theme.json color patch.', 'adapter content intent global styles negative case', 'global_styles_write_not_supported' );
maa_adapter_smoke_assert_content_intent_fails_closed( 'Directly execute a custom HTML template change.', 'adapter content intent custom HTML negative case', 'custom_html_template_not_supported' );
maa_adapter_smoke_assert( ! isset( $help['route_groups']['read_shortcuts'] ), 'adapter help does not expose grouped read shortcut routes' );
maa_adapter_smoke_assert( 'npcink_governance_core_admin' === (string) ( $help['approval_surface'] ?? '' ), 'adapter help exposes Core admin approval surface' );
maa_adapter_smoke_assert( array_key_exists( 'core_app_token_configured', $help ), 'adapter help exposes Core app token configured state without token value' );

$smoke_terms = get_terms(
	array(
		'taxonomy'   => 'category',
		'hide_empty' => false,
		'number'     => 1,
	)
);
maa_adapter_smoke_assert( ! is_wp_error( $smoke_terms ) && ! empty( $smoke_terms ), 'WordPress has a category term for adapter term detail smoke' );
$smoke_term  = $smoke_terms[0];
$term_detail = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id' => 'npcink-abilities-toolkit/get-term',
		'input'      => array(
			'id' => (int) $smoke_term->term_id,
		),
	)
);
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/get-term' === (string) ( $term_detail['ability_id'] ?? '' ), 'adapter resolves term detail from list id' );

$capabilities = maa_adapter_smoke_rest( 'GET', '/npcink-openclaw-adapter/v1/capabilities' );
$core_request_event = maa_adapter_smoke_observability_event( 'adapter.core.request', 'ok', '/npcink-governance-core/v1/capabilities' );
maa_adapter_smoke_assert( ! empty( $core_request_event ), 'adapter emits Core relay success event for capabilities' );
maa_adapter_smoke_assert( 'GET' === (string) ( $core_request_event['method'] ?? '' ), 'adapter Core relay success event carries method' );
maa_adapter_smoke_assert( 200 === (int) ( $core_request_event['status_code'] ?? 0 ), 'adapter Core relay success event carries status code' );
maa_adapter_smoke_assert_observability_safe( $core_request_event, 'adapter Core relay success event' );

$missing_core_proposal = maa_adapter_smoke_rest_result( 'GET', '/npcink-openclaw-adapter/v1/proposals/missing-observability-smoke' );
maa_adapter_smoke_assert( 404 === (int) $missing_core_proposal['status'], 'adapter Core relay failure smoke returns missing proposal status' );
$core_request_error_event = maa_adapter_smoke_observability_event( 'adapter.core.request', 'error', '/npcink-governance-core/v1/proposals/missing-observability-smoke' );
maa_adapter_smoke_assert( ! empty( $core_request_error_event ), 'adapter emits Core relay failure event for missing proposal' );
maa_adapter_smoke_assert( 404 === (int) ( $core_request_error_event['status_code'] ?? 0 ), 'adapter Core relay failure event carries status code' );
maa_adapter_smoke_assert( '' !== (string) ( $core_request_error_event['error_code'] ?? '' ), 'adapter Core relay failure event carries stable error code' );
maa_adapter_smoke_assert_observability_safe( $core_request_error_event, 'adapter Core relay failure event' );
$by_id        = maa_adapter_smoke_capabilities_by_id( $capabilities );
maa_adapter_smoke_assert( isset( $by_id['npcink-abilities-toolkit/site-info'] ), 'adapter exposes site-info capability through Core' );
maa_adapter_smoke_assert( isset( $by_id['npcink-abilities-toolkit/wp-diagnostics-summary'] ), 'adapter exposes diagnostics capability through Core' );
maa_adapter_smoke_assert( isset( $by_id['npcink-abilities-toolkit/wp-ops-diagnostics-detail'] ), 'adapter exposes ops diagnostics capability through Core' );
maa_adapter_smoke_assert( isset( $by_id['npcink-abilities-toolkit/list-workflow-recipes'] ), 'adapter exposes workflow recipe list capability through Core' );
maa_adapter_smoke_assert( isset( $by_id['npcink-abilities-toolkit/get-workflow-recipe'] ), 'adapter exposes workflow recipe detail capability through Core' );
maa_adapter_smoke_assert( isset( $by_id['npcink-abilities-toolkit/build-content-inventory-fix-plan'] ), 'adapter capabilities expose content inventory fix plan through Core' );
maa_adapter_smoke_assert( isset( $by_id['npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan'] ), 'adapter capabilities expose nonproduction content cleanup plan through Core' );
maa_adapter_smoke_assert( isset( $by_id['npcink-abilities-toolkit/build-media-inventory-fix-plan'] ), 'adapter capabilities expose media inventory fix plan through Core' );
maa_adapter_smoke_assert( isset( $by_id['npcink-abilities-toolkit/build-media-reference-repair-plan'] ), 'adapter capabilities expose media reference repair plan through Core' );
maa_adapter_smoke_assert( isset( $by_id['npcink-abilities-toolkit/build-media-settings-reference-repair-plan'] ), 'adapter capabilities expose media settings reference repair plan through Core' );
maa_adapter_smoke_assert( isset( $by_id['npcink-abilities-toolkit/optimize-media-metadata'] ), 'adapter capabilities expose media metadata optimization through Core' );
maa_adapter_smoke_assert( 'direct_read' === (string) ( $by_id['npcink-abilities-toolkit/site-info']['governance_mode'] ?? '' ), 'site-info is direct read' );

$content_plan_response = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id' => 'npcink-abilities-toolkit/build-content-inventory-fix-plan',
		'input'      => array(
			'per_page'    => 1,
			'max_actions' => 1,
		),
	)
);
$content_plan = is_array( $content_plan_response['result']['data'] ?? null ) ? $content_plan_response['result']['data'] : array();
maa_adapter_smoke_assert( array_key_exists( 'write_actions', $content_plan ), 'adapter plan read preserves write_actions' );
maa_adapter_smoke_assert( array_key_exists( 'preview', $content_plan ), 'adapter plan read preserves preview' );
maa_adapter_smoke_assert( array_key_exists( 'risk', $content_plan ), 'adapter plan read preserves risk' );
maa_adapter_smoke_assert( true === (bool) ( $content_plan['requires_approval'] ?? false ), 'adapter plan read preserves requires_approval=true' );
maa_adapter_smoke_assert( false === (bool) ( $content_plan['commit_execution'] ?? true ), 'adapter plan read preserves commit_execution=false' );
maa_adapter_smoke_assert( true === (bool) ( $content_plan['dry_run'] ?? false ), 'adapter plan read preserves dry_run=true' );
maa_adapter_smoke_assert( false === (bool) ( $content_plan_response['commit_execution'] ?? true ), 'adapter plan wrapper does not report execution' );
maa_adapter_smoke_assert( 'direct_read_internal' === (string) ( $content_plan_response['read_policy'] ?? '' ), 'adapter plan read carries internal read policy' );
maa_adapter_smoke_assert( 'internal' === (string) ( $content_plan_response['sensitivity'] ?? '' ), 'adapter plan read carries internal sensitivity' );
maa_adapter_smoke_assert( false === (bool) ( $content_plan_response['redaction_required'] ?? true ), 'adapter plan read does not require redaction' );

$article_optimization_title = 'Adapter Article Optimization Candidate ' . maa_adapter_smoke_run_id();
$article_optimization_post_id = wp_insert_post(
	array(
		'post_title'   => $article_optimization_title,
		'post_content' => 'Adapter article optimization smoke content. The original post should remain unchanged while the plan is reviewed.',
		'post_excerpt' => 'Original adapter smoke excerpt.',
		'post_status'  => 'draft',
		'post_type'    => 'post',
	),
	true
);
maa_adapter_smoke_assert( ! is_wp_error( $article_optimization_post_id ) && (int) $article_optimization_post_id > 0, 'adapter article optimization fixture post is created' );
$article_optimization_post_id = (int) $article_optimization_post_id;
$maa_adapter_smoke_cleanup_post_ids[] = $article_optimization_post_id;
$article_optimization_excerpt = 'Reviewed adapter smoke excerpt for Core proposal intake.';
$article_optimization_plan_input = array(
	'post'              => array(
		'id'      => $article_optimization_post_id,
		'title'   => $article_optimization_title,
		'status'  => 'draft',
		'excerpt' => 'Original adapter smoke excerpt.',
	),
	'report'            => array(
		'summary' => array(
			'status'                => 'needs_attention',
			'high_priority_count'   => 1,
			'total_recommendations' => 2,
		),
		'geo' => array(
			'summary' => array(
				'faq_candidate_count' => 1,
			),
		),
	),
	'optimization_plan' => array(
		'excerpt_mode' => 'apply',
		'seo_mode'     => 'suggest',
	),
	'generated_excerpt' => array(
		'proposal_text' => $article_optimization_excerpt,
	),
);
$article_optimization_plan_response = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id' => 'npcink-abilities-toolkit/build-article-optimization-apply-plan',
		'input'      => $article_optimization_plan_input,
	)
);
$article_optimization_plan = is_array( $article_optimization_plan_response['result']['data'] ?? null ) ? $article_optimization_plan_response['result']['data'] : array();
maa_adapter_smoke_assert( 'article_optimization_apply_plan' === (string) ( $article_optimization_plan['artifact_type'] ?? '' ), 'adapter article optimization read returns apply plan artifact' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/recipes/article-optimization' === (string) ( $article_optimization_plan['source_recipe_ref'] ?? '' ), 'adapter article optimization read preserves source recipe ref' );
maa_adapter_smoke_assert( false === (bool) ( $article_optimization_plan['direct_wordpress_write'] ?? true ), 'adapter article optimization plan disables direct WordPress writes' );
$article_optimization_bridge = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals/from-plan',
	array(
		'plan_ability_id'    => 'npcink-abilities-toolkit/build-article-optimization-apply-plan',
		'plan'               => $article_optimization_plan_response['result'],
		'plan_input'         => $article_optimization_plan_input,
		'adapter_request_id' => 'adapter-article-optimization-e2e-request',
		'correlation_id'     => 'adapter-article-optimization-e2e-correlation',
		'caller'             => array(
			'external_thread_id' => 'adapter-article-optimization-e2e-smoke',
		),
	)
);
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/build-article-optimization-apply-plan' === (string) ( $article_optimization_bridge['plan_ability_id'] ?? '' ), 'adapter forwards article optimization plan to Core' );
maa_adapter_smoke_assert( false === (bool) ( $article_optimization_bridge['commit_execution'] ?? true ), 'adapter article optimization handoff preserves commit_execution=false' );
maa_adapter_smoke_assert( 1 === (int) ( $article_optimization_bridge['proposal_count'] ?? 0 ), 'adapter article optimization plan creates one Core proposal' );
$article_optimization_proposal = is_array( $article_optimization_bridge['proposals'][0] ?? null ) ? $article_optimization_bridge['proposals'][0] : array();
$article_optimization_proposal_id = (string) ( $article_optimization_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $article_optimization_proposal_id;
maa_adapter_smoke_assert( '' !== $article_optimization_proposal_id, 'adapter article optimization bridge returned proposal id' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/update-post' === (string) ( $article_optimization_proposal['ability_id'] ?? '' ), 'adapter article optimization creates update-post proposal' );
maa_adapter_smoke_assert( $article_optimization_excerpt === (string) ( $article_optimization_proposal['input']['excerpt'] ?? '' ), 'adapter article optimization proposal preserves reviewed excerpt' );
$article_optimization_detail = maa_adapter_smoke_rest( 'GET', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $article_optimization_proposal_id ) );
maa_adapter_smoke_assert( $article_optimization_proposal_id === (string) ( $article_optimization_detail['proposal_id'] ?? '' ), 'adapter article optimization proposal detail is readable through adapter' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/recipes/article-optimization' === (string) ( $article_optimization_detail['preview']['article_optimization']['source_recipe_ref'] ?? '' ), 'adapter article optimization detail preserves recipe ref' );
maa_adapter_smoke_assert( false === (bool) ( $article_optimization_detail['preview']['article_optimization']['direct_wordpress_write'] ?? true ), 'adapter article optimization detail keeps direct writes disabled' );
maa_adapter_smoke_assert( 'Original adapter smoke excerpt.' === (string) get_post_field( 'post_excerpt', $article_optimization_post_id ), 'adapter article optimization handoff does not mutate the post excerpt' );

$article_media_handoff_attachment_id = maa_adapter_smoke_create_media_plan_attachment();
$maa_adapter_smoke_cleanup_attachment_ids[] = $article_media_handoff_attachment_id;
$article_media_handoff_input = array(
	'article'                   => array(
		'title'   => 'Adapter Article Media Handoff Candidate ' . maa_adapter_smoke_run_id(),
		'excerpt' => 'Adapter article media handoff smoke context.',
	),
	'resolved_image_source'     => array(
		'featured' => array(
			'image_origin'     => 'ai_generated',
			'prompt'           => 'Generated adapter media handoff proof image',
			'title'            => 'Reviewed media handoff title',
			'alt'              => 'Reviewed media handoff alt text',
			'caption'          => 'Reviewed media handoff caption.',
			'description'      => 'Reviewed media handoff description.',
			'copyright_notice' => 'Generated asset for this site',
			'role'             => 'featured',
			'provider_hint'    => 'adapter_smoke',
			'section_heading'  => 'Workflow proof',
		),
	),
	'generated_featured_upload' => array(
		'attachment_id' => $article_media_handoff_attachment_id,
		'url'           => wp_get_attachment_url( $article_media_handoff_attachment_id ),
		'file_name'     => 'adapter-article-media-handoff-smoke.jpg',
	),
);
$article_media_handoff_response = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id' => 'npcink-abilities-toolkit/build-media-seo-assets',
		'input'      => $article_media_handoff_input,
	)
);
$article_media_handoff_assets = is_array( $article_media_handoff_response['result']['data'] ?? null ) ? $article_media_handoff_response['result']['data'] : array();
$article_media_handoff_asset = is_array( $article_media_handoff_assets['items'][0] ?? null ) ? $article_media_handoff_assets['items'][0] : array();
maa_adapter_smoke_assert( 1 === (int) ( $article_media_handoff_assets['summary']['asset_count'] ?? 0 ), 'adapter article media handoff returns one media SEO asset' );
maa_adapter_smoke_assert( $article_media_handoff_attachment_id === (int) ( $article_media_handoff_asset['attachment_id'] ?? 0 ), 'adapter article media handoff binds the reviewed attachment id' );
maa_adapter_smoke_assert( false === (bool) ( $article_media_handoff_response['commit_execution'] ?? true ), 'adapter article media handoff read does not execute commits' );
maa_adapter_smoke_assert( ! isset( $article_media_handoff_assets['write_actions'] ), 'adapter article media handoff read does not emit write actions' );

$article_media_handoff_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/update-media-details',
		'title'      => 'Adapter article media handoff proposal smoke',
		'summary'    => 'Adapter creates one Core proposal from reviewed article media handoff metadata without direct write execution.',
		'input'      => array(
			'attachment_id'    => $article_media_handoff_attachment_id,
			'title'            => $article_media_handoff_asset['title'] ?? '',
			'alt'              => $article_media_handoff_asset['alt'] ?? '',
			'caption'          => $article_media_handoff_asset['caption'] ?? '',
			'description'      => $article_media_handoff_asset['description'] ?? '',
			'source_type'      => $article_media_handoff_asset['source_type'] ?? 'ai_generated',
			'copyright_notice' => $article_media_handoff_asset['copyright_notice'] ?? '',
			'dry_run'          => true,
			'commit'           => false,
		),
		'preview'    => array(
			'action'                => 'update_media_details',
			'attachment_id'         => $article_media_handoff_attachment_id,
			'changed_fields'        => array( 'title', 'alt', 'caption', 'description', 'source_type', 'copyright_notice' ),
			'dry_run'               => true,
			'commit_execution'      => false,
			'article_media_handoff' => array(
				'source_recipe_ref'              => 'npcink-abilities-toolkit/recipes/article-media-handoff',
				'entrypoint_ability_id'          => 'npcink-abilities-toolkit/build-media-seo-assets',
				'direct_wordpress_write'         => false,
				'host_governed_write_boundary'   => true,
				'disallowed_default_ability_ids' => array(
					'npcink-abilities-toolkit/upload-media-from-url',
					'npcink-abilities-toolkit/set-post-featured-image',
				),
			),
		),
		'caller'     => array(
			'external_thread_id' => 'adapter-article-media-handoff-smoke',
		),
	)
);
$article_media_handoff_proposal_id = (string) ( $article_media_handoff_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $article_media_handoff_proposal_id;
maa_adapter_smoke_assert( '' !== $article_media_handoff_proposal_id, 'adapter article media handoff creates Core proposal' );
maa_adapter_smoke_assert( 'pending' === (string) ( $article_media_handoff_proposal['status'] ?? '' ), 'adapter article media handoff proposal starts pending' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/update-media-details' === (string) ( $article_media_handoff_proposal['ability_id'] ?? '' ), 'adapter article media handoff creates update-media-details proposal' );
$article_media_handoff_detail = maa_adapter_smoke_rest( 'GET', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $article_media_handoff_proposal_id ) );
maa_adapter_smoke_assert( $article_media_handoff_proposal_id === (string) ( $article_media_handoff_detail['proposal_id'] ?? '' ), 'adapter article media handoff proposal detail is readable through adapter' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/recipes/article-media-handoff' === (string) ( $article_media_handoff_detail['preview']['article_media_handoff']['source_recipe_ref'] ?? '' ), 'adapter article media handoff detail preserves recipe ref' );
maa_adapter_smoke_assert( false === (bool) ( $article_media_handoff_detail['preview']['article_media_handoff']['direct_wordpress_write'] ?? true ), 'adapter article media handoff detail keeps direct writes disabled' );
maa_adapter_smoke_assert( 'Adapter Media Plan Smoke' === (string) get_the_title( $article_media_handoff_attachment_id ), 'adapter article media handoff proposal does not mutate media title' );
maa_adapter_smoke_assert( '' === (string) get_post_meta( $article_media_handoff_attachment_id, '_wp_attachment_image_alt', true ), 'adapter article media handoff proposal does not mutate media alt text' );
maa_adapter_smoke_assert( '' === (string) get_post_meta( $article_media_handoff_attachment_id, '_npcink_ai_media_source_type', true ), 'adapter article media handoff proposal does not mutate media source type' );

$media_plan_response = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id' => 'npcink-abilities-toolkit/build-media-inventory-fix-plan',
		'input'      => array(
			'per_page'                  => 1,
			'max_actions'               => 1,
			'include_delete_candidates' => false,
		),
	)
);
$media_plan = is_array( $media_plan_response['result']['data'] ?? null ) ? $media_plan_response['result']['data'] : array();
maa_adapter_smoke_assert( array_key_exists( 'skipped_destructive_candidates', $media_plan ), 'adapter media plan preserves skipped destructive candidates field' );
foreach ( (array) ( $media_plan['write_actions'] ?? array() ) as $media_action ) {
	maa_adapter_smoke_assert( ! is_array( $media_action ) || 'npcink-abilities-toolkit/delete-media-permanently' !== (string) ( $media_action['target_ability_id'] ?? '' ), 'adapter media plan does not promote skipped deletes into write actions by default' );
}

$media_plan_shortcut = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id' => 'npcink-abilities-toolkit/build-media-inventory-fix-plan',
		'input'      => array(
			'per_page'                  => 1,
			'max_actions'               => 1,
			'include_delete_candidates'  => 'false',
			'include_trash_parent_media' => 'false',
			'include_unattached_nonproduction_media' => 'false',
		),
	)
);
$media_shortcut_plan = is_array( $media_plan_shortcut['result']['data'] ?? null ) ? $media_plan_shortcut['result']['data'] : array();
foreach ( (array) ( $media_shortcut_plan['write_actions'] ?? array() ) as $media_shortcut_action ) {
	maa_adapter_smoke_assert( ! is_array( $media_shortcut_action ) || 'npcink-abilities-toolkit/delete-media-permanently' !== (string) ( $media_shortcut_action['target_ability_id'] ?? '' ), 'adapter media plan shortcut treats include_delete_candidates=false as false' );
	maa_adapter_smoke_assert( ! is_array( $media_shortcut_action ) || 'npcink-abilities-toolkit/delete-media-permanently' !== (string) ( $media_shortcut_action['target_ability_id'] ?? '' ), 'adapter media plan shortcut treats include_trash_parent_media=false as false' );
	maa_adapter_smoke_assert( ! is_array( $media_shortcut_action ) || 'npcink-abilities-toolkit/delete-media-permanently' !== (string) ( $media_shortcut_action['target_ability_id'] ?? '' ), 'adapter media plan shortcut treats include_unattached_nonproduction_media=false as false' );
}

$unallowed_plan_bridge = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals/from-plan',
	array(
		'plan_ability_id' => 'npcink-abilities-toolkit/site-info',
		'plan'            => array(
			'requires_approval' => true,
			'commit_execution'  => false,
			'dry_run'           => true,
			'write_actions'     => array(),
		),
	)
);
maa_adapter_smoke_assert( 400 === (int) $unallowed_plan_bridge['status'], 'adapter rejects unallowed plan-to-proposal ability before Core forwarding' );
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_plan_ability_unsupported' === (string) ( $unallowed_plan_bridge['data']['code'] ?? '' ), 'adapter unallowed plan rejection uses adapter error code' );
$plan_ingest_error_event = maa_adapter_smoke_observability_event( 'adapter.proposal.plan_ingest', 'error', '/npcink-openclaw-adapter/v1/proposals/from-plan' );
maa_adapter_smoke_assert( ! empty( $plan_ingest_error_event ), 'adapter emits plan handoff failure observability event' );
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_plan_ability_unsupported' === (string) ( $plan_ingest_error_event['error_code'] ?? '' ), 'adapter plan handoff failure event carries stable error code' );
maa_adapter_smoke_assert( 400 === (int) ( $plan_ingest_error_event['status_code'] ?? 0 ), 'adapter plan handoff failure event carries status code' );
maa_adapter_smoke_assert_observability_safe( $plan_ingest_error_event, 'adapter plan handoff failure event' );
$plan_dispatch_error_event = maa_adapter_smoke_observability_event( 'adapter.openclaw.dispatch.failed', 'error', '/npcink-openclaw-adapter/v1/proposals/from-plan' );
maa_adapter_smoke_assert( ! empty( $plan_dispatch_error_event ), 'adapter emits OpenClaw dispatch failed event for plan handoff failure' );
maa_adapter_smoke_assert( 400 === (int) ( $plan_dispatch_error_event['status_code'] ?? 0 ), 'adapter dispatch failed event carries status code' );
maa_adapter_smoke_assert_observability_safe( $plan_dispatch_error_event, 'adapter dispatch failed event' );

$invalid_plan_action_bridge = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals/from-plan',
	array(
		'plan_ability_id' => 'npcink-toolbox/build-article-write-plan',
		'plan'            => array(
			'requires_approval' => true,
			'commit_execution'  => false,
			'dry_run'           => true,
			'write_actions'     => array(
				array(
					'action_id'         => 'invalid-update-post-status',
					'target_ability_id' => 'npcink-abilities-toolkit/update-post',
					'requires_approval' => true,
					'commit_execution'  => false,
					'proposal_ready'    => true,
					'input'             => array(
						'post_id' => 123,
						'title'   => 'Adapter invalid plan action status should not apply',
						'status'  => 'publish',
						'dry_run' => true,
						'commit'  => false,
					),
				),
			),
		),
	)
);
$invalid_plan_action_error = is_array( $invalid_plan_action_bridge['data']['data'] ?? null ) ? $invalid_plan_action_bridge['data']['data'] : array();
$invalid_plan_action_block = is_array( $invalid_plan_action_error['blocked_items'][0] ?? null ) ? $invalid_plan_action_error['blocked_items'][0] : array();
maa_adapter_smoke_assert( 400 === (int) $invalid_plan_action_bridge['status'], 'adapter plan-to-proposal rejects invalid profiled action input before Core forwarding' );
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_plan_action_input_invalid' === (string) ( $invalid_plan_action_bridge['data']['code'] ?? '' ), 'adapter plan action input rejection uses adapter error code' );
maa_adapter_smoke_assert( 0 === (int) ( $invalid_plan_action_error['proposal_count'] ?? -1 ), 'adapter plan action input rejection creates no proposals' );
maa_adapter_smoke_assert( 0 === (int) ( $invalid_plan_action_block['index'] ?? -1 ), 'adapter plan action input rejection carries action index' );
maa_adapter_smoke_assert( 'invalid-update-post-status' === (string) ( $invalid_plan_action_block['action_id'] ?? '' ), 'adapter plan action input rejection carries action id' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/update-post' === (string) ( $invalid_plan_action_block['target_ability_id'] ?? '' ), 'adapter plan action input rejection carries target ability id' );
maa_adapter_smoke_assert( 'status' === (string) ( $invalid_plan_action_block['field'] ?? '' ), 'adapter plan action input rejection carries field' );
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_ability_input_field_unsupported' === (string) ( $invalid_plan_action_block['block_code'] ?? '' ), 'adapter plan action input rejection reuses proposal schema field error code' );

$duplicate_plan_action_bridge = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals/from-plan',
	array(
		'plan_ability_id' => 'npcink-abilities-toolkit/build-content-inventory-fix-plan',
		'plan'            => array(
			'requires_approval' => true,
			'commit_execution'  => false,
			'dry_run'           => true,
			'write_actions'     => array(
				array(
					'action_id'         => 'duplicate-plan-action',
					'target_ability_id' => 'npcink-abilities-toolkit/create-draft',
					'input'             => array(
						'title'   => 'Adapter duplicate plan action one',
						'dry_run' => true,
						'commit'  => false,
					),
					'requires_approval' => true,
					'commit_execution'  => false,
					'proposal_ready'    => true,
				),
				array(
					'action_id'         => 'duplicate-plan-action',
					'target_ability_id' => 'npcink-abilities-toolkit/create-draft',
					'input'             => array(
						'title'   => 'Adapter duplicate plan action two',
						'dry_run' => true,
						'commit'  => false,
					),
					'requires_approval' => true,
					'commit_execution'  => false,
					'proposal_ready'    => true,
				),
			),
		),
	)
);
$duplicate_plan_action_error = is_array( $duplicate_plan_action_bridge['data']['data'] ?? null ) ? $duplicate_plan_action_bridge['data']['data'] : array();
$duplicate_plan_action_block = is_array( $duplicate_plan_action_error['blocked_items'][0] ?? null ) ? $duplicate_plan_action_error['blocked_items'][0] : array();
maa_adapter_smoke_assert( 400 === (int) $duplicate_plan_action_bridge['status'], 'adapter plan-to-proposal rejects duplicate action ids before Core forwarding' );
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_write_action_duplicate_id' === (string) ( $duplicate_plan_action_block['block_code'] ?? '' ), 'adapter duplicate plan action rejection uses duplicate id block code' );

$embedded_output_plan_bridge = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals/from-plan',
	array(
		'plan_ability_id' => 'npcink-abilities-toolkit/build-content-inventory-fix-plan',
		'plan'            => array(
			'requires_approval' => true,
			'commit_execution'  => false,
			'dry_run'           => true,
			'write_actions'     => array(
				array(
					'action_id'         => 'embedded-output-token',
					'target_ability_id' => 'npcink-abilities-toolkit/create-draft',
					'input'             => array(
						'title'   => 'Adapter embedded output token smoke',
						'content' => 'prefix-$outputs.embedded-output-token.post_id',
						'dry_run' => true,
						'commit'  => false,
					),
					'requires_approval' => true,
					'commit_execution'  => false,
					'proposal_ready'    => true,
				),
			),
		),
	)
);
$embedded_output_error = is_array( $embedded_output_plan_bridge['data']['data'] ?? null ) ? $embedded_output_plan_bridge['data']['data'] : array();
$embedded_output_block = is_array( $embedded_output_error['blocked_items'][0] ?? null ) ? $embedded_output_error['blocked_items'][0] : array();
maa_adapter_smoke_assert( 400 === (int) $embedded_output_plan_bridge['status'], 'adapter plan-to-proposal rejects embedded output reference tokens before Core forwarding' );
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_output_reference_invalid' === (string) ( $embedded_output_block['block_code'] ?? '' ), 'adapter embedded plan output token rejection uses output reference invalid code' );

$output_reference_plan_bridge = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals/from-plan',
	array(
		'plan_ability_id' => 'npcink-abilities-toolkit/build-content-inventory-fix-plan',
		'plan'            => array(
			'success' => true,
			'data'    => array(
				'batch_id'         => 'adapter-plan-output-reference-smoke',
				'issue_types'      => array( 'acceptance' ),
				'write_actions'    => array(
					array(
						'action_id'         => 'create-draft-fixture',
						'target_ability_id' => 'npcink-abilities-toolkit/create-draft',
						'input'             => array(
							'status'         => 'draft',
							'title'          => 'Adapter from-plan output reference draft',
							'content'        => 'Adapter from-plan output reference smoke.',
							'content_format' => 'plain',
							'dry_run'        => true,
							'commit'         => false,
						),
						'requires_approval' => true,
						'commit_execution'  => false,
						'proposal_ready'    => true,
					),
					array(
						'action_id'         => 'update-created-draft',
						'target_ability_id' => 'npcink-abilities-toolkit/update-post',
						'depends_on'        => array( 'create-draft-fixture' ),
						'input'             => array(
							'post_id'        => '$outputs.create-draft-fixture.post_id',
							'title'          => 'Adapter from-plan output reference updated draft',
							'content'        => 'Adapter resolved a from-plan output reference before update.',
							'content_format' => 'plain',
							'dry_run'        => true,
							'commit'         => false,
						),
						'requires_approval' => true,
						'commit_execution'  => false,
						'proposal_ready'    => true,
					),
					array(
						'action_id'         => 'trash-created-draft',
						'target_ability_id' => 'npcink-abilities-toolkit/trash-post',
						'depends_on'        => array( 'create-draft-fixture' ),
						'input'             => array(
							'post_id' => '$outputs.create-draft-fixture.post_id',
							'dry_run' => true,
							'commit'  => false,
						),
						'requires_approval' => true,
						'commit_execution'  => false,
						'proposal_ready'    => true,
					),
				),
				'preview'          => array(),
				'risk'             => array(
					'level'  => 'medium',
					'reason' => 'Adapter from-plan output reference smoke.',
				),
				'requires_approval' => true,
				'commit_execution' => false,
				'dry_run'          => true,
			),
		),
	)
);
maa_adapter_smoke_assert( 1 === (int) ( $output_reference_plan_bridge['proposal_count'] ?? 0 ), 'adapter from-plan output references create one batch proposal' );
$output_reference_plan_proposal = is_array( $output_reference_plan_bridge['proposals'][0] ?? null ) ? $output_reference_plan_bridge['proposals'][0] : array();
$output_reference_plan_proposal_id = (string) ( $output_reference_plan_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $output_reference_plan_proposal_id;
$output_reference_plan_actions = is_array( $output_reference_plan_proposal['input']['write_actions'] ?? null ) ? array_values( $output_reference_plan_proposal['input']['write_actions'] ) : array();
maa_adapter_smoke_assert( 'plan_to_proposal_batch' === (string) ( $output_reference_plan_proposal['preview']['source']['type'] ?? '' ), 'adapter from-plan output reference proposal records batch source' );
maa_adapter_smoke_assert( 3 === count( $output_reference_plan_actions ), 'adapter from-plan output reference proposal preserves ordered actions' );
maa_adapter_smoke_assert( '$outputs.create-draft-fixture.post_id' === (string) ( $output_reference_plan_actions[1]['input']['post_id'] ?? '' ), 'adapter from-plan output reference proposal preserves unresolved post_id reference' );
$output_reference_plan_result = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $output_reference_plan_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( true === (bool) ( $output_reference_plan_result['success'] ?? false ), 'adapter from-plan output-reference batch approve-and-execute succeeds' );
maa_adapter_smoke_assert( 3 === (int) ( $output_reference_plan_result['executed_count'] ?? 0 ), 'adapter from-plan output-reference batch executes all actions' );
$output_reference_plan_post_id = (int) ( $output_reference_plan_result['results'][0]['post_id'] ?? 0 );
$maa_adapter_smoke_cleanup_post_ids[] = $output_reference_plan_post_id;
maa_adapter_smoke_assert( $output_reference_plan_post_id > 0, 'adapter from-plan output-reference batch creates a draft post' );
maa_adapter_smoke_assert( $output_reference_plan_post_id === (int) ( $output_reference_plan_result['results'][1]['post_id'] ?? 0 ), 'adapter from-plan output-reference batch updates the created draft' );
maa_adapter_smoke_assert( $output_reference_plan_post_id === (int) ( $output_reference_plan_result['results'][2]['post_id'] ?? 0 ), 'adapter from-plan output-reference batch trashes the created draft' );
maa_adapter_smoke_assert( 'trash' === (string) get_post_status( $output_reference_plan_post_id ), 'adapter from-plan output-reference batch leaves created draft trashed' );

$content_metadata_post_id = maa_adapter_smoke_create_trash_post_fixture();
$maa_adapter_smoke_cleanup_post_ids[] = $content_metadata_post_id;
$content_metadata_category = wp_insert_term( 'Adapter Metadata Category ' . wp_generate_uuid4(), 'category' );
maa_adapter_smoke_assert( ! is_wp_error( $content_metadata_category ) && (int) ( $content_metadata_category['term_id'] ?? 0 ) > 0, 'adapter smoke created content metadata category fixture' );
$content_metadata_category_id = (int) $content_metadata_category['term_id'];
$maa_adapter_smoke_cleanup_terms[] = array(
	'term_id'  => $content_metadata_category_id,
	'taxonomy' => 'category',
);
$content_metadata_tag = wp_insert_term( 'Adapter Metadata Tag ' . wp_generate_uuid4(), 'post_tag' );
maa_adapter_smoke_assert( ! is_wp_error( $content_metadata_tag ) && (int) ( $content_metadata_tag['term_id'] ?? 0 ) > 0, 'adapter smoke created content metadata tag fixture' );
$content_metadata_tag_id = (int) $content_metadata_tag['term_id'];
$maa_adapter_smoke_cleanup_terms[] = array(
	'term_id'  => $content_metadata_tag_id,
	'taxonomy' => 'post_tag',
);
$content_metadata_excerpt = 'Adapter content metadata apply excerpt smoke.';
$content_metadata_bridge = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals/from-plan',
	array(
		'plan_ability_id' => 'npcink-abilities-toolkit/build-content-metadata-apply-plan',
		'plan'            => array(
			'success' => true,
			'data'    => array(
				'artifact_type'           => 'content_metadata_apply_plan',
				'version'                 => 1,
				'batch_id'                => 'adapter-content-metadata-apply-smoke',
				'proposal_mode'           => 'batch',
				'batch_approval'          => true,
				'target_post_id'          => $content_metadata_post_id,
				'post'                    => array(
					'post_id' => $content_metadata_post_id,
				),
				'accepted_choices'        => array(
					'excerpt'      => $content_metadata_excerpt,
					'category_ids' => array( $content_metadata_category_id ),
					'tag_ids'      => array( $content_metadata_tag_id ),
				),
				'authorization'           => array(
					'classification'    => 'core_proposal_required',
					'decision_version'  => 'operation-classification-v1',
					'decision_envelope' => array(
						'classification'             => 'core_proposal_required',
						'request_source'             => 'external_adapter',
						'actor_presence'             => 'delegated',
						'preview_completeness'       => 'sufficient',
						'scope'                      => 'one_object',
						'reversibility'              => 'easy_undo',
						'operation_kind'             => 'batch_plan',
						'writes_wordpress_state'     => true,
					),
				),
				'write_actions'           => array(
					array(
						'action_id'         => 'apply-reviewed-excerpt',
						'target_ability_id' => 'npcink-abilities-toolkit/update-post',
						'input'             => array(
							'post_id' => $content_metadata_post_id,
							'excerpt' => $content_metadata_excerpt,
							'dry_run' => true,
							'commit'  => false,
						),
						'requires_approval' => true,
						'commit_execution'  => false,
						'proposal_ready'    => true,
					),
					array(
						'action_id'         => 'apply-reviewed-categories',
						'target_ability_id' => 'npcink-abilities-toolkit/set-post-terms',
						'input'             => array(
							'post_id'        => $content_metadata_post_id,
							'taxonomy'       => 'category',
							'mode'           => 'append',
							'term_ids'       => array( $content_metadata_category_id ),
							'terms'          => array(),
							'create_missing' => false,
							'dry_run'        => true,
							'commit'         => false,
						),
						'requires_approval' => true,
						'commit_execution'  => false,
						'proposal_ready'    => true,
					),
					array(
						'action_id'         => 'apply-reviewed-tags',
						'target_ability_id' => 'npcink-abilities-toolkit/set-post-terms',
						'input'             => array(
							'post_id'        => $content_metadata_post_id,
							'taxonomy'       => 'post_tag',
							'mode'           => 'append',
							'term_ids'       => array( $content_metadata_tag_id ),
							'terms'          => array(),
							'create_missing' => false,
							'dry_run'        => true,
							'commit'         => false,
						),
						'requires_approval' => true,
						'commit_execution'  => false,
						'proposal_ready'    => true,
					),
				),
				'manual_review'           => array(),
				'skipped_destructive_candidates' => array(),
				'risk'                    => array(
					'level'  => 'medium',
					'reason' => 'Adapter content metadata apply smoke.',
				),
				'requires_approval'       => true,
				'direct_wordpress_write'  => false,
				'commit_execution'        => false,
				'dry_run'                 => true,
			),
		),
	)
);
maa_adapter_smoke_assert( 1 === (int) ( $content_metadata_bridge['proposal_count'] ?? 0 ), 'adapter content metadata apply plan creates one Core batch proposal' );
$content_metadata_proposal = is_array( $content_metadata_bridge['proposals'][0] ?? null ) ? $content_metadata_bridge['proposals'][0] : array();
$content_metadata_proposal_id = (string) ( $content_metadata_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $content_metadata_proposal_id;
maa_adapter_smoke_assert( 'plan_to_proposal_batch' === (string) ( $content_metadata_proposal['preview']['source']['type'] ?? '' ), 'adapter content metadata apply proposal records batch source' );
maa_adapter_smoke_assert( 'content_metadata_apply_plan' === (string) ( $content_metadata_proposal['preview']['content_metadata_apply']['artifact_type'] ?? '' ), 'adapter content metadata apply proposal preserves metadata preview' );
$content_metadata_execute = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $content_metadata_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( true === (bool) ( $content_metadata_execute['success'] ?? false ), 'adapter content metadata apply batch approve-and-execute succeeds' );
maa_adapter_smoke_assert( 3 === (int) ( $content_metadata_execute['executed_count'] ?? 0 ), 'adapter content metadata apply batch executes excerpt, category, and tag actions' );
$content_metadata_post_after = get_post( $content_metadata_post_id );
maa_adapter_smoke_assert( is_object( $content_metadata_post_after ) && $content_metadata_excerpt === (string) $content_metadata_post_after->post_excerpt, 'adapter content metadata apply batch writes reviewed excerpt' );
$content_metadata_category_ids = wp_get_post_terms( $content_metadata_post_id, 'category', array( 'fields' => 'ids' ) );
maa_adapter_smoke_assert( ! is_wp_error( $content_metadata_category_ids ) && in_array( $content_metadata_category_id, array_map( 'intval', (array) $content_metadata_category_ids ), true ), 'adapter content metadata apply batch assigns reviewed category' );
$content_metadata_tag_ids = wp_get_post_terms( $content_metadata_post_id, 'post_tag', array( 'fields' => 'ids' ) );
maa_adapter_smoke_assert( ! is_wp_error( $content_metadata_tag_ids ) && in_array( $content_metadata_tag_id, array_map( 'intval', (array) $content_metadata_tag_ids ), true ), 'adapter content metadata apply batch assigns reviewed tag' );

$article_plan = array(
	'artifact_type'           => 'article_write_plan',
	'version'                 => 1,
	'batch_id'                => 'adapter-article-draft-plan-smoke',
	'proposal_mode'           => 'single',
	'requires_approval'       => true,
	'commit_execution'        => false,
	'dry_run'                 => true,
	'article_goal_brief'      => array(
		'title'    => 'Adapter article draft plan smoke',
		'audience' => 'Local acceptance operator',
		'goal'     => 'Verify OpenClaw article plan handoff creates only a draft.',
	),
	'research_evidence_pack'  => array(
		'evidence_items' => array(
			array(
				'source'  => 'local smoke fixture',
				'summary' => 'The fixture is bounded and contains no external claims.',
			),
		),
	),
	'article_outline'         => array(
		'sections' => array(
			array(
				'heading' => 'Smoke coverage',
				'points'  => array( 'Adapter forwards plan', 'Core governs proposal', 'Abilities creates draft' ),
			),
		),
	),
	'article_draft_candidate' => array(
		'title'            => 'Adapter Article Draft Plan Smoke',
		'content_markdown' => "Adapter article draft plan smoke.\n\nThis draft was created through Core approval and Adapter execution.",
		'excerpt'          => 'Adapter article plan smoke draft.',
	),
	'discoverability_pack'    => array(
		'focus_keyword'   => 'adapter article plan smoke',
		'seo_title'       => 'Adapter Article Draft Plan Smoke',
		'seo_description' => 'Local smoke verification for article draft plan handoff.',
	),
	'article_risk_report'     => array(
		'ready_for_proposal' => true,
		'risk_level'         => 'medium',
		'blocked_claims'     => array(),
		'notes'              => array( 'Fixture content is local and draft-only.' ),
	),
	'write_actions'           => array(
		array(
			'action_id'         => 'create-article-draft',
			'target_ability_id' => 'npcink-abilities-toolkit/create-draft',
			'input'             => array(
				'status'         => 'draft',
				'title'          => 'Adapter Article Draft Plan Smoke',
				'content'        => "Adapter article draft plan smoke.\n\nThis draft was created through Core approval and Adapter execution.",
				'content_format' => 'plain',
				'excerpt'        => 'Adapter article plan smoke draft.',
				'dry_run'        => true,
				'commit'         => false,
			),
			'requires_approval' => true,
			'commit_execution'  => false,
			'proposal_ready'    => true,
		),
	),
	'preview'                 => array(),
	'risk'                    => array(
		'level'  => 'medium',
		'reason' => 'Article fixture is draft-only and ready for proposal.',
	),
	'action_count'            => 1,
);
$article_plan_bridge_result = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals/from-plan',
	array(
		'plan_ability_id'    => 'npcink-toolbox/build-article-write-plan',
		'plan'               => $article_plan,
		'plan_input'         => array(
			'title' => 'Adapter Article Draft Plan Smoke',
		),
		'adapter_request_id' => 'adapter-article-plan-request',
		'correlation_id'     => 'adapter-article-plan-correlation',
		'caller'             => array(
			'external_thread_id' => 'adapter-article-plan-smoke',
		),
	)
);
$article_plan_bridge = is_array( $article_plan_bridge_result['data'] ) ? $article_plan_bridge_result['data'] : array();
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_plan_ability_unsupported' !== (string) ( $article_plan_bridge['code'] ?? '' ), 'adapter does not reject article plan at Adapter supported profiles' );
if ( 404 === (int) $article_plan_bridge_result['status'] && 'npcink_governance_core_plan_ability_unavailable' === (string) ( $article_plan_bridge['code'] ?? '' ) ) {
	maa_adapter_smoke_assert( '/npcink-governance-core/v1/proposals/from-plan' === (string) ( $article_plan_bridge['data']['upstream_route'] ?? '' ), 'adapter forwards article plan to Core when local Core catalog lacks the planning ability' );
} else {
	maa_adapter_smoke_assert( $article_plan_bridge_result['status'] >= 200 && $article_plan_bridge_result['status'] < 300, 'POST /npcink-openclaw-adapter/v1/proposals/from-plan accepts article plan' );
	maa_adapter_smoke_assert( 'npcink-toolbox/build-article-write-plan' === (string) ( $article_plan_bridge['plan_ability_id'] ?? '' ), 'adapter forwards Toolbox article plan to Core' );
	maa_adapter_smoke_assert( 1 === (int) ( $article_plan_bridge['proposal_count'] ?? 0 ), 'adapter article plan creates one Core proposal' );
	$article_plan_proposal    = is_array( $article_plan_bridge['proposals'][0] ?? null ) ? $article_plan_bridge['proposals'][0] : array();
	$article_plan_proposal_id = (string) ( $article_plan_proposal['proposal_id'] ?? '' );
	$maa_adapter_smoke_cleanup_proposal_ids[] = $article_plan_proposal_id;
	maa_adapter_smoke_assert( 'npcink-abilities-toolkit/create-draft' === (string) ( $article_plan_proposal['ability_id'] ?? '' ), 'adapter article plan creates a create-draft proposal' );
	maa_adapter_smoke_assert( 'draft' === (string) ( $article_plan_proposal['input']['status'] ?? '' ), 'adapter article plan proposal is draft-only' );
	maa_adapter_smoke_assert( 'article_write_plan' === (string) ( $article_plan_proposal['preview']['article_workflow']['artifact_type'] ?? '' ), 'adapter article plan proposal preserves article workflow preview' );
	$plan_ingest_success_event = maa_adapter_smoke_observability_event( 'adapter.proposal.plan_ingest', 'ok', '/npcink-openclaw-adapter/v1/proposals/from-plan' );
	maa_adapter_smoke_assert( ! empty( $plan_ingest_success_event ), 'adapter emits plan handoff success observability event' );
	maa_adapter_smoke_assert( 'adapter-article-plan-request' === (string) ( $plan_ingest_success_event['adapter_request_id'] ?? '' ), 'adapter plan handoff success event carries adapter request id' );
	maa_adapter_smoke_assert( 'adapter-article-plan-correlation' === (string) ( $plan_ingest_success_event['correlation_id'] ?? '' ), 'adapter plan handoff success event carries correlation id' );
	maa_adapter_smoke_assert_observability_safe( $plan_ingest_success_event, 'adapter plan handoff success event' );
	$article_plan_execute = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $article_plan_proposal_id ) . '/approve-and-execute' );
	maa_adapter_smoke_assert( true === (bool) ( $article_plan_execute['success'] ?? false ), 'adapter article plan approve-and-execute succeeds' );
	maa_adapter_smoke_assert( 'npcink-abilities-toolkit/create-draft' === (string) ( $article_plan_execute['ability_id'] ?? '' ), 'adapter article plan execution uses create-draft ability' );
	$article_plan_post_id = (int) ( $article_plan_execute['post_id'] ?? 0 );
	$maa_adapter_smoke_cleanup_post_ids[] = $article_plan_post_id;
	maa_adapter_smoke_assert( $article_plan_post_id > 0, 'adapter article plan execution returns created draft post id' );
	maa_adapter_smoke_assert( 'draft' === (string) get_post_status( $article_plan_post_id ), 'adapter article plan creates only a WordPress draft' );
}

$article_batch_plan = $article_plan;
$article_batch_plan['artifact_type'] = 'article_batch_write_plan';
$article_batch_plan['batch_id'] = 'adapter_article_batch_plan_smoke';
$article_batch_plan['proposal_mode'] = 'batch';
$article_batch_plan['batch_approval'] = true;
$article_batch_plan['article_batch_risk_report'] = array(
	'ready_for_proposal' => true,
	'risk_level'         => 'medium',
	'blocked_claims'     => array(),
	'notes'              => array( 'Fixture batch is local and draft-only.' ),
);
$article_batch_plan['write_actions'] = array();
$article_batch_plan['articles']      = array();
for ( $article_batch_index = 1; $article_batch_index <= 3; $article_batch_index++ ) {
	$article_batch_title   = 'Adapter Article Batch Draft Plan Smoke ' . $article_batch_index;
	$article_batch_content = "Adapter article batch draft plan smoke {$article_batch_index}.\n\nThis draft candidate stays pending until Core approval.";
	$article_batch_excerpt = 'Adapter article batch plan smoke draft.';
	$article_batch_plan['articles'][] = array(
		'article_goal_brief'      => array(
			'topic' => 'Adapter article batch draft plan smoke',
			'title' => $article_batch_title,
		),
		'research_evidence_pack'  => array(
			'sources' => array(),
		),
		'article_outline'         => array(
			'title'    => $article_batch_title,
			'sections' => array(),
		),
		'article_draft_candidate' => array(
			'content_markdown' => $article_batch_content,
		),
		'discoverability_pack'    => array(
			'excerpt' => $article_batch_excerpt,
		),
		'article_risk_report'     => array(
			'ready_for_proposal' => true,
			'risk_level'         => 'medium',
			'blocked_claims'     => array(),
		),
	);
	$article_batch_plan['write_actions'][] = array(
		'action_id'         => 'create-article-draft-' . $article_batch_index,
		'target_ability_id' => 'npcink-abilities-toolkit/create-draft',
		'input'             => array(
			'status'         => 'draft',
			'title'          => $article_batch_title,
			'content'        => $article_batch_content,
			'content_format' => 'plain',
			'excerpt'        => $article_batch_excerpt,
			'dry_run'        => true,
			'commit'         => false,
		),
		'requires_approval' => true,
		'commit_execution'  => false,
		'proposal_ready'    => true,
	);
}
$article_batch_plan['action_count'] = count( $article_batch_plan['write_actions'] );
$article_batch_bridge_result = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals/from-plan',
	array(
		'plan_ability_id'    => 'npcink-toolbox/build-article-batch-write-plan',
		'plan'               => $article_batch_plan,
		'plan_input'         => array(
			'topic'         => 'Adapter article batch draft plan smoke',
			'article_count' => 3,
		),
		'adapter_request_id' => 'adapter-article-batch-plan-request',
		'correlation_id'     => 'adapter-article-batch-plan-correlation',
		'caller'             => array(
			'external_thread_id' => 'adapter-article-batch-plan-smoke',
		),
	)
);
$article_batch_bridge_data = is_array( $article_batch_bridge_result['data'] ) ? $article_batch_bridge_result['data'] : array();
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_plan_ability_unsupported' !== (string) ( $article_batch_bridge_data['code'] ?? '' ), 'adapter does not reject article batch plan at Adapter supported profiles' );
if ( 404 === (int) $article_batch_bridge_result['status'] && 'npcink_governance_core_plan_ability_unavailable' === (string) ( $article_batch_bridge_data['code'] ?? '' ) ) {
	maa_adapter_smoke_assert( '/npcink-governance-core/v1/proposals/from-plan' === (string) ( $article_batch_bridge_data['data']['upstream_route'] ?? '' ), 'adapter forwards article batch plan to Core when local Core catalog lacks the planning ability' );
} else {
	maa_adapter_smoke_assert( $article_batch_bridge_result['status'] >= 200 && $article_batch_bridge_result['status'] < 300, 'POST /npcink-openclaw-adapter/v1/proposals/from-plan accepts article batch plan' );
	maa_adapter_smoke_assert( 'npcink-toolbox/build-article-batch-write-plan' === (string) ( $article_batch_bridge_data['plan_ability_id'] ?? '' ), 'adapter forwards Toolbox article batch plan to Core' );
	maa_adapter_smoke_assert( 1 === (int) ( $article_batch_bridge_data['proposal_count'] ?? 0 ), 'adapter article batch plan creates one Core batch proposal' );
	$article_batch_proposal    = is_array( $article_batch_bridge_data['proposals'][0] ?? null ) ? $article_batch_bridge_data['proposals'][0] : array();
	$article_batch_proposal_id = (string) ( $article_batch_proposal['proposal_id'] ?? '' );
	$maa_adapter_smoke_cleanup_proposal_ids[] = $article_batch_proposal_id;
	maa_adapter_smoke_assert( 3 === count( (array) ( $article_batch_proposal['input']['write_actions'] ?? array() ) ), 'adapter article batch plan proposal preserves three write actions' );
}

$blocked_article_plan = $article_plan;
$blocked_article_plan['article_risk_report']['risk_level'] = 'high';
$blocked_article_plan['article_risk_report']['ready_for_proposal'] = true;
$blocked_article_handoff = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals/from-plan',
	array(
		'plan_ability_id' => 'npcink-toolbox/build-article-write-plan',
		'plan'            => $blocked_article_plan,
		'plan_input'      => array(
			'title' => 'Adapter blocked article plan handoff',
		),
	)
);
if ( 404 === (int) $blocked_article_handoff['status'] && 'npcink_governance_core_plan_ability_unavailable' === (string) ( $blocked_article_handoff['data']['code'] ?? '' ) ) {
	maa_adapter_smoke_assert( '/npcink-governance-core/v1/proposals/from-plan' === (string) ( $blocked_article_handoff['data']['data']['upstream_route'] ?? '' ), 'adapter forwards blocked article plan to Core when local Core catalog lacks the planning ability' );
} else {
	maa_adapter_smoke_assert( 422 === (int) $blocked_article_handoff['status'], 'adapter article plan handoff returns Core intake failure' );
	maa_adapter_smoke_assert( 'plan_revision_required' === (string) ( $blocked_article_handoff['data']['data']['operator_feedback']['status'] ?? '' ), 'adapter article plan handoff returns operator feedback' );
	maa_adapter_smoke_assert( 'npcink_governance_core_article_plan_risk_blocked' === (string) ( $blocked_article_handoff['data']['data']['operator_feedback']['core_evidence']['core_error_code'] ?? '' ), 'adapter article plan handoff feedback preserves Core error code' );
	maa_adapter_smoke_assert( true === (bool) ( $blocked_article_handoff['data']['data']['operator_feedback']['can_retry_after_revision'] ?? false ), 'adapter article plan handoff feedback marks revision retryable' );
}

$media_plan_attachment_id = maa_adapter_smoke_create_media_plan_attachment();
$maa_adapter_smoke_cleanup_attachment_ids[] = $media_plan_attachment_id;
$media_e2e_input = array(
	'attachment_ids'              => array( $media_plan_attachment_id ),
	'issue_types'                 => array( 'missing_alt', 'missing_source', 'possibly_unattached' ),
	'include_delete_candidates'   => false,
	'max_actions'                 => 5,
);
$media_e2e_plan_response = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id' => 'npcink-abilities-toolkit/build-media-inventory-fix-plan',
		'input'      => $media_e2e_input,
	)
);
$media_e2e_plan = is_array( $media_e2e_plan_response['result']['data'] ?? null ) ? $media_e2e_plan_response['result']['data'] : array();
maa_adapter_smoke_assert( ! empty( $media_e2e_plan['write_actions'] ), 'adapter e2e media plan contains write_actions before proposal handoff' );
maa_adapter_smoke_assert( ! empty( $media_e2e_plan['manual_review'] ), 'adapter e2e media plan contains manual_review rows before proposal handoff' );
maa_adapter_smoke_assert( ! empty( $media_e2e_plan['skipped_destructive_candidates'] ), 'adapter e2e media plan contains skipped destructive candidates before proposal handoff' );
foreach ( (array) ( $media_e2e_plan['write_actions'] ?? array() ) as $media_e2e_action ) {
	maa_adapter_smoke_assert( ! is_array( $media_e2e_action ) || 'npcink-abilities-toolkit/delete-media-permanently' !== (string) ( $media_e2e_action['target_ability_id'] ?? '' ), 'adapter e2e media plan keeps delete-media-permanently out of write_actions by default' );
}

$plan_bridge = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals/from-plan',
	array(
		'plan_ability_id'    => 'npcink-abilities-toolkit/build-media-inventory-fix-plan',
		'plan'               => $media_e2e_plan_response['result'],
		'plan_input'         => $media_e2e_input,
		'adapter_request_id' => 'adapter-plan-e2e-request',
		'correlation_id'     => 'adapter-plan-e2e-correlation',
		'caller'             => array(
			'external_thread_id' => 'adapter-plan-bridge-smoke',
		),
	)
);
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/build-media-inventory-fix-plan' === (string) ( $plan_bridge['plan_ability_id'] ?? '' ), 'adapter forwards plan-to-proposal route to Core' );
maa_adapter_smoke_assert( false === (bool) ( $plan_bridge['commit_execution'] ?? true ), 'adapter plan-to-proposal response preserves commit_execution=false' );
maa_adapter_smoke_assert( is_array( $plan_bridge['proposals'] ?? null ), 'adapter plan-to-proposal response preserves proposal list state' );
maa_adapter_smoke_assert( (int) ( $plan_bridge['proposal_count'] ?? 0 ) >= 1, 'adapter e2e media plan creates Core proposal' );
foreach ( (array) ( $plan_bridge['proposals'] ?? array() ) as $created_plan_proposal ) {
	if ( is_array( $created_plan_proposal ) && '' !== (string) ( $created_plan_proposal['proposal_id'] ?? '' ) ) {
		$maa_adapter_smoke_cleanup_proposal_ids[] = (string) $created_plan_proposal['proposal_id'];
		maa_adapter_smoke_assert( 'npcink-abilities-toolkit/delete-media-permanently' !== (string) ( $created_plan_proposal['ability_id'] ?? '' ), 'adapter e2e media plan keeps delete-media-permanently out of created proposals by default' );
	}
}
$plan_proposal = is_array( $plan_bridge['proposals'][0] ?? null ) ? $plan_bridge['proposals'][0] : array();
$plan_proposal_id = (string) ( $plan_proposal['proposal_id'] ?? '' );
maa_adapter_smoke_assert( '' !== $plan_proposal_id, 'adapter e2e media plan returned a proposal id' );
maa_adapter_smoke_assert( 'adapter-plan-e2e-request' === (string) ( $plan_proposal['caller']['adapter_request_id'] ?? '' ), 'adapter plan proposal caller carries adapter request id' );
$plan_proposal_detail = maa_adapter_smoke_rest( 'GET', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $plan_proposal_id ) );
maa_adapter_smoke_assert( $plan_proposal_id === (string) ( $plan_proposal_detail['proposal_id'] ?? '' ), 'adapter e2e plan proposal detail is readable through adapter' );
maa_adapter_smoke_assert( is_array( $plan_proposal_detail['preview'] ?? null ), 'adapter e2e plan proposal detail preserves preview object' );
maa_adapter_smoke_assert( is_array( $plan_proposal_detail['input'] ?? null ), 'adapter e2e plan proposal detail preserves write action input' );
maa_adapter_smoke_assert( 'plan_to_proposal' === (string) ( $plan_proposal_detail['preview']['source']['type'] ?? '' ), 'adapter e2e plan proposal detail preserves source plan preview' );
maa_adapter_smoke_assert( is_array( $plan_proposal_detail['preview']['plan_preview_row'] ?? null ), 'adapter e2e plan proposal detail preserves source plan preview row' );
maa_adapter_smoke_assert( ! empty( $plan_proposal_detail['preview']['blocked_items']['manual_review'] ?? array() ), 'adapter e2e plan proposal detail preserves manual review rows' );
maa_adapter_smoke_assert( ! empty( $plan_proposal_detail['preview']['blocked_items']['skipped_destructive_candidates'] ?? array() ), 'adapter e2e plan proposal detail preserves skipped destructive candidates' );
maa_adapter_smoke_assert( 'adapter-plan-e2e-request' === (string) ( $plan_proposal_detail['caller']['adapter_request_id'] ?? '' ), 'adapter e2e proposal detail preserves adapter request id in caller' );
maa_adapter_smoke_assert( 'adapter-plan-e2e-correlation' === (string) ( $plan_proposal_detail['caller']['correlation_id'] ?? '' ), 'adapter e2e proposal detail preserves correlation id in caller' );

$pattern_page_media_attachment_id = maa_adapter_smoke_create_real_media_attachment( 'adapter-visual-smoke-' );
$maa_adapter_smoke_cleanup_attachment_ids[] = $pattern_page_media_attachment_id;
$pattern_page_media_url = (string) wp_get_attachment_url( $pattern_page_media_attachment_id );
maa_adapter_smoke_assert( '' !== $pattern_page_media_url, 'adapter pattern page smoke has an existing media URL' );
$pattern_page_plan_input = array(
	'post_type'          => 'page',
	'status'             => 'draft',
	'title'              => 'Adapter Pattern Page E2E Smoke',
	'pattern_id'         => 'openai-style-landing',
	'style_preset'       => 'minimal-dark-light',
	'responsive_profile' => 'landing_standard',
	'visual_density'     => 'balanced',
	'media_strategy'     => 'existing_media_url',
	'variables'          => array(
		'eyebrow'          => 'OpenClaw E2E',
		'hero_title'       => 'Responsive Gutenberg Pattern Smoke',
		'hero_description' => 'Adapter routes this reviewed pattern page plan through Core proposal governance.',
		'primary_cta'      => 'Review proposal',
		'secondary_cta'    => 'Inspect blocks',
		'hero_media_url'   => $pattern_page_media_url,
		'hero_media_alt'   => 'Adapter pattern page smoke media',
		'features'         => array(
			array(
				'title'       => 'Responsive blocks',
				'description' => 'Columns stack on mobile and media-text uses a supplied media URL.',
			),
		),
		'faq'              => array(
			array(
				'title'       => 'Does Adapter render this page?',
				'description' => 'No. Toolkit renders the block plan and Adapter only handles Core proposal handoff and approved execution.',
			),
		),
	),
);
$pattern_page_plan_response = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id' => 'npcink-abilities-toolkit/build-pattern-page-plan',
		'input'      => $pattern_page_plan_input,
	)
);
$pattern_page_plan = is_array( $pattern_page_plan_response['result']['data'] ?? null ) ? $pattern_page_plan_response['result']['data'] : array();
maa_adapter_smoke_assert( 'pattern_page_plan' === (string) ( $pattern_page_plan['artifact_type'] ?? '' ), 'adapter pattern page read returns a pattern_page_plan artifact' );
maa_adapter_smoke_assert( '3.0' === (string) ( $pattern_page_plan['design_quality']['pattern_version'] ?? '' ), 'adapter pattern page plan uses v3 quality metadata' );
maa_adapter_smoke_assert( true === (bool) ( $pattern_page_plan['responsive_quality']['uses_mobile_stack'] ?? false ), 'adapter pattern page plan reports mobile stacking' );
maa_adapter_smoke_assert( true === (bool) ( $pattern_page_plan['responsive_quality']['has_media_section'] ?? false ), 'adapter pattern page plan reports media section' );
maa_adapter_smoke_assert( true === (bool) ( $pattern_page_plan['responsive_quality']['has_faq'] ?? false ), 'adapter pattern page plan reports FAQ section' );
$pattern_page_bridge = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals/from-plan',
	array(
		'plan_ability_id'    => 'npcink-abilities-toolkit/build-pattern-page-plan',
		'plan'               => $pattern_page_plan_response['result'],
		'plan_input'         => $pattern_page_plan_input,
		'adapter_request_id' => 'adapter-pattern-page-e2e-request',
		'correlation_id'     => 'adapter-pattern-page-e2e-correlation',
		'caller'             => array(
			'external_thread_id' => 'adapter-pattern-page-e2e-smoke',
		),
	)
);
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/build-pattern-page-plan' === (string) ( $pattern_page_bridge['plan_ability_id'] ?? '' ), 'adapter forwards pattern page plan to Core' );
maa_adapter_smoke_assert( 1 === (int) ( $pattern_page_bridge['proposal_count'] ?? 0 ), 'adapter pattern page plan creates one batch proposal' );
$pattern_page_proposal = is_array( $pattern_page_bridge['proposals'][0] ?? null ) ? $pattern_page_bridge['proposals'][0] : array();
$pattern_page_proposal_id = (string) ( $pattern_page_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $pattern_page_proposal_id;
maa_adapter_smoke_assert( '' !== $pattern_page_proposal_id, 'adapter pattern page bridge returned proposal id' );
$pattern_page_execute = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $pattern_page_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( true === (bool) ( $pattern_page_execute['success'] ?? false ), 'adapter pattern page approve-and-execute succeeds' );
maa_adapter_smoke_assert( 2 === (int) ( $pattern_page_execute['executed_count'] ?? 0 ), 'adapter pattern page batch executes create and update actions' );
$pattern_page_execution_record = is_array( $pattern_page_execute['execution_record'] ?? null ) ? $pattern_page_execute['execution_record'] : array();
$pattern_page_execution_verification = is_array( $pattern_page_execution_record['verification'] ?? null ) ? $pattern_page_execution_record['verification'] : array();
maa_adapter_smoke_assert( 'recorded' === (string) ( $pattern_page_execution_verification['status'] ?? '' ), 'adapter pattern page execution record persists verification summary' );
maa_adapter_smoke_assert( 1 <= (int) ( $pattern_page_execution_verification['aggregates']['block_readback_verified_count'] ?? 0 ), 'adapter pattern page execution verifies post-block readback' );
maa_adapter_smoke_assert( 0 === (int) ( $pattern_page_execution_verification['aggregates']['block_readback_failed_count'] ?? -1 ), 'adapter pattern page execution has no failed block readbacks' );
$pattern_page_post_id = 0;
foreach ( (array) ( $pattern_page_execute['results'] ?? array() ) as $pattern_page_result ) {
	if ( is_array( $pattern_page_result ) && 'npcink-abilities-toolkit/create-draft' === (string) ( $pattern_page_result['target_ability_id'] ?? '' ) ) {
		$pattern_page_post_id = absint( $pattern_page_result['post_id'] ?? 0 );
		break;
	}
}
$maa_adapter_smoke_cleanup_post_ids[] = $pattern_page_post_id;
maa_adapter_smoke_assert( $pattern_page_post_id > 0, 'adapter pattern page execution returns created draft page id' );
maa_adapter_smoke_assert( 'draft' === (string) get_post_status( $pattern_page_post_id ), 'adapter pattern page execution leaves page in draft status' );
$pattern_page_blocks_shortcut = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id' => 'npcink-abilities-toolkit/get-post-blocks',
		'input'      => array(
			'post_id'              => $pattern_page_post_id,
			'include_inner_blocks' => true,
		),
	)
);
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/get-post-blocks' === (string) ( $pattern_page_blocks_shortcut['ability_id'] ?? '' ), 'adapter pattern page smoke reads blocks through generic read ability route' );
$pattern_page_blocks_result = is_array( $pattern_page_blocks_shortcut['result'] ?? null ) ? $pattern_page_blocks_shortcut['result'] : array();
maa_adapter_smoke_assert( (int) ( $pattern_page_blocks_result['block_count'] ?? 0 ) >= 7, 'adapter pattern page block read returns expected section count' );
$pattern_page_content = (string) get_post_field( 'post_content', $pattern_page_post_id );
$pattern_page_blocks = parse_blocks( $pattern_page_content );
$pattern_page_blocks_json = wp_json_encode( $pattern_page_blocks );
maa_adapter_smoke_assert( is_string( $pattern_page_blocks_json ) && false !== strpos( $pattern_page_blocks_json, '"blockName":"core\\/media-text"' ), 'adapter pattern page draft contains media-text block' );
maa_adapter_smoke_assert( is_string( $pattern_page_blocks_json ) && false !== strpos( $pattern_page_blocks_json, '"blockName":"core\\/details"' ), 'adapter pattern page draft contains FAQ details block' );
maa_adapter_smoke_assert( is_string( $pattern_page_blocks_json ) && false !== strpos( $pattern_page_blocks_json, '"isStackedOnMobile":true' ), 'adapter pattern page draft keeps mobile stacking attrs' );
maa_adapter_smoke_assert( false !== strpos( $pattern_page_content, '<!-- wp:' ), 'adapter pattern page draft stores Gutenberg block comments' );
maa_adapter_smoke_assert( false !== strpos( $pattern_page_content, $pattern_page_media_url ), 'adapter pattern page draft preserves reviewed media URL' );
maa_adapter_smoke_assert( false !== strpos( $pattern_page_content, 'Adapter pattern page smoke media' ), 'adapter pattern page draft preserves reviewed media alt text' );
maa_adapter_smoke_assert_gutenberg_images_are_complete( 'adapter pattern page draft', $pattern_page_content );
maa_adapter_smoke_assert_gutenberg_content_quality( 'adapter pattern page draft', $pattern_page_content, $pattern_page_blocks, 8 );
$pattern_page_visual_fixture = maa_adapter_smoke_record_gutenberg_visual_acceptance_fixture(
	'pattern_page_plan',
	$pattern_page_post_id,
	array( $pattern_page_media_attachment_id ),
	array(
		'block_count'             => (int) ( $pattern_page_blocks_result['block_count'] ?? 0 ),
		'has_gutenberg_comments'  => false !== strpos( $pattern_page_content, '<!-- wp:' ),
		'has_media_text'          => is_string( $pattern_page_blocks_json ) && false !== strpos( $pattern_page_blocks_json, '"blockName":"core\\/media-text"' ),
		'has_faq_details'         => is_string( $pattern_page_blocks_json ) && false !== strpos( $pattern_page_blocks_json, '"blockName":"core\\/details"' ),
		'has_mobile_stack_attrs'  => is_string( $pattern_page_blocks_json ) && false !== strpos( $pattern_page_blocks_json, '"isStackedOnMobile":true' ),
		'has_complete_images'     => true,
		'padded_block_count'      => maa_adapter_smoke_count_padded_blocks( $pattern_page_blocks ),
	)
);
maa_adapter_smoke_assert( '' !== (string) ( $pattern_page_visual_fixture['front_end_url'] ?? '' ), 'adapter pattern page visual acceptance fixture has front-end URL' );
maa_adapter_smoke_assert( '' !== (string) ( $pattern_page_visual_fixture['block_editor_url'] ?? '' ), 'adapter pattern page visual acceptance fixture has block editor URL' );
maa_adapter_smoke_assert( 3 === count( (array) ( $pattern_page_visual_fixture['viewports'] ?? array() ) ), 'adapter pattern page visual acceptance fixture has three viewport targets' );

$article_block_media_attachment_id = maa_adapter_smoke_create_real_media_attachment( 'adapter-visual-smoke-' );
$maa_adapter_smoke_cleanup_attachment_ids[] = $article_block_media_attachment_id;
$article_block_media_url = (string) wp_get_attachment_url( $article_block_media_attachment_id );
maa_adapter_smoke_assert( '' !== $article_block_media_url, 'adapter article block smoke has an existing media URL' );
$article_block_plan_input = array(
	'post_type'          => 'post',
	'status'             => 'draft',
	'title'              => 'Adapter Article Block E2E Smoke',
	'article_template'   => 'comparison-review',
	'responsive_profile' => 'article_standard',
	'media_strategy'     => 'existing_media_url',
	'variables'          => array(
		'dek'            => 'OpenClaw can use Gutenberg-native article blocks without making Adapter a renderer.',
		'intro'          => 'Adapter routes this reviewed article block plan through Core proposal governance.',
		'hero_media_url' => $article_block_media_url,
		'hero_media_alt' => 'Adapter article block smoke media',
		'takeaways'      => array(
			'Article plans create draft posts only.',
			'Block replacement stays behind Core approval and preflight.',
			'Comparison columns stack on mobile.',
		),
		'sections'       => array(
			array(
				'title'      => 'Editorial structure',
				'paragraphs' => array( 'The article is split into headings, paragraphs, lists, image, comparison columns, and FAQ details.' ),
				'bullets'    => array( 'Readable structure', 'Editable blocks', 'Auditable proposal' ),
			),
			array(
				'title'      => 'Governance boundary',
				'paragraphs' => array( 'Toolkit builds the plan, Core owns proposal truth, and Adapter executes only approved supported actions.' ),
			),
			array(
				'title'      => 'Responsive behavior',
				'paragraphs' => array( 'Columns must use Gutenberg mobile stacking and images must remain standard core/image blocks.' ),
			),
		),
		'comparisons'    => array(
			array(
				'title'       => 'Inline HTML',
				'description' => 'Flexible but more likely to become hard to edit.',
			),
			array(
				'title'       => 'Gutenberg blocks',
				'description' => 'Structured, reviewable, and easier to maintain.',
			),
		),
		'faq'            => array(
			array(
				'title'       => 'Does Adapter generate this article?',
				'description' => 'No. Toolkit builds the block plan; Adapter only handles Core handoff and approved execution.',
			),
			array(
				'title'       => 'Does this publish the post?',
				'description' => 'No. The created post remains draft.',
			),
		),
	),
);
$article_block_plan_response = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id' => 'npcink-abilities-toolkit/build-article-block-plan',
		'input'      => $article_block_plan_input,
	)
);
$article_block_plan = is_array( $article_block_plan_response['result']['data'] ?? null ) ? $article_block_plan_response['result']['data'] : array();
maa_adapter_smoke_assert( 'article_block_plan' === (string) ( $article_block_plan['artifact_type'] ?? '' ), 'adapter article block read returns an article_block_plan artifact' );
maa_adapter_smoke_assert( '1.0' === (string) ( $article_block_plan['editorial_quality']['pattern_version'] ?? '' ), 'adapter article block plan uses v1 quality metadata' );
maa_adapter_smoke_assert( true === (bool) ( $article_block_plan['editorial_quality']['uses_native_blocks'] ?? false ), 'adapter article block plan reports native block usage' );
maa_adapter_smoke_assert( true === (bool) ( $article_block_plan['responsive_quality']['uses_mobile_stack'] ?? false ), 'adapter article block plan reports mobile stacking' );
maa_adapter_smoke_assert( true === (bool) ( $article_block_plan['responsive_quality']['has_responsive_media'] ?? false ), 'adapter article block plan reports media block' );
$article_block_bridge = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals/from-plan',
	array(
		'plan_ability_id'    => 'npcink-abilities-toolkit/build-article-block-plan',
		'plan'               => $article_block_plan_response['result'],
		'plan_input'         => $article_block_plan_input,
		'adapter_request_id' => 'adapter-article-block-e2e-request',
		'correlation_id'     => 'adapter-article-block-e2e-correlation',
		'caller'             => array(
			'external_thread_id' => 'adapter-article-block-e2e-smoke',
		),
	)
);
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/build-article-block-plan' === (string) ( $article_block_bridge['plan_ability_id'] ?? '' ), 'adapter forwards article block plan to Core' );
maa_adapter_smoke_assert( 1 === (int) ( $article_block_bridge['proposal_count'] ?? 0 ), 'adapter article block plan creates one batch proposal' );
$article_block_proposal = is_array( $article_block_bridge['proposals'][0] ?? null ) ? $article_block_bridge['proposals'][0] : array();
$article_block_proposal_id = (string) ( $article_block_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $article_block_proposal_id;
maa_adapter_smoke_assert( '' !== $article_block_proposal_id, 'adapter article block bridge returned proposal id' );
$article_block_execute = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $article_block_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( true === (bool) ( $article_block_execute['success'] ?? false ), 'adapter article block approve-and-execute succeeds' );
maa_adapter_smoke_assert( 2 === (int) ( $article_block_execute['executed_count'] ?? 0 ), 'adapter article block batch executes create and update actions' );
$article_block_execution_record = is_array( $article_block_execute['execution_record'] ?? null ) ? $article_block_execute['execution_record'] : array();
$article_block_execution_verification = is_array( $article_block_execution_record['verification'] ?? null ) ? $article_block_execution_record['verification'] : array();
maa_adapter_smoke_assert( 'recorded' === (string) ( $article_block_execution_verification['status'] ?? '' ), 'adapter article block execution record persists verification summary' );
maa_adapter_smoke_assert( 1 <= (int) ( $article_block_execution_verification['aggregates']['block_readback_verified_count'] ?? 0 ), 'adapter article block execution verifies post-block readback' );
maa_adapter_smoke_assert( 0 === (int) ( $article_block_execution_verification['aggregates']['block_readback_failed_count'] ?? -1 ), 'adapter article block execution has no failed block readbacks' );
$article_block_post_id = 0;
foreach ( (array) ( $article_block_execute['results'] ?? array() ) as $article_block_result ) {
	if ( is_array( $article_block_result ) && 'npcink-abilities-toolkit/create-draft' === (string) ( $article_block_result['target_ability_id'] ?? '' ) ) {
		$article_block_post_id = absint( $article_block_result['post_id'] ?? 0 );
		break;
	}
}
$maa_adapter_smoke_cleanup_post_ids[] = $article_block_post_id;
maa_adapter_smoke_assert( $article_block_post_id > 0, 'adapter article block execution returns created draft post id' );
maa_adapter_smoke_assert( 'draft' === (string) get_post_status( $article_block_post_id ), 'adapter article block execution leaves post in draft status' );
$article_block_blocks_shortcut = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id' => 'npcink-abilities-toolkit/get-post-blocks',
		'input'      => array(
			'post_id'              => $article_block_post_id,
			'include_inner_blocks' => true,
		),
	)
);
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/get-post-blocks' === (string) ( $article_block_blocks_shortcut['ability_id'] ?? '' ), 'adapter article block smoke reads blocks through generic read ability route' );
$article_block_blocks_result = is_array( $article_block_blocks_shortcut['result'] ?? null ) ? $article_block_blocks_shortcut['result'] : array();
maa_adapter_smoke_assert( (int) ( $article_block_blocks_result['block_count'] ?? 0 ) >= 10, 'adapter article block read returns expected article block count' );
$article_block_content = (string) get_post_field( 'post_content', $article_block_post_id );
$article_block_blocks = parse_blocks( $article_block_content );
$article_block_blocks_json = wp_json_encode( $article_block_blocks );
maa_adapter_smoke_assert( is_string( $article_block_blocks_json ) && false !== strpos( $article_block_blocks_json, '"blockName":"core\\/image"' ), 'adapter article block draft contains image block' );
maa_adapter_smoke_assert( is_string( $article_block_blocks_json ) && false !== strpos( $article_block_blocks_json, '"blockName":"core\\/columns"' ), 'adapter article block draft contains comparison columns' );
maa_adapter_smoke_assert( is_string( $article_block_blocks_json ) && false !== strpos( $article_block_blocks_json, '"blockName":"core\\/details"' ), 'adapter article block draft contains FAQ details block' );
maa_adapter_smoke_assert( is_string( $article_block_blocks_json ) && false !== strpos( $article_block_blocks_json, '"isStackedOnMobile":true' ), 'adapter article block draft keeps mobile stacking attrs' );
maa_adapter_smoke_assert( false !== strpos( $article_block_content, '<!-- wp:' ), 'adapter article block draft stores Gutenberg block comments' );
maa_adapter_smoke_assert( false !== strpos( $article_block_content, $article_block_media_url ), 'adapter article block draft preserves reviewed media URL' );
maa_adapter_smoke_assert( false !== strpos( $article_block_content, 'Adapter article block smoke media' ), 'adapter article block draft preserves reviewed media alt text' );
maa_adapter_smoke_assert_gutenberg_images_are_complete( 'adapter article block draft', $article_block_content );
maa_adapter_smoke_assert_gutenberg_content_quality( 'adapter article block draft', $article_block_content, $article_block_blocks, 2 );
$article_block_visual_fixture = maa_adapter_smoke_record_gutenberg_visual_acceptance_fixture(
	'article_block_plan',
	$article_block_post_id,
	array( $article_block_media_attachment_id ),
	array(
		'block_count'             => (int) ( $article_block_blocks_result['block_count'] ?? 0 ),
		'has_gutenberg_comments'  => false !== strpos( $article_block_content, '<!-- wp:' ),
		'has_image'               => is_string( $article_block_blocks_json ) && false !== strpos( $article_block_blocks_json, '"blockName":"core\\/image"' ),
		'has_comparison_columns'  => is_string( $article_block_blocks_json ) && false !== strpos( $article_block_blocks_json, '"blockName":"core\\/columns"' ),
		'has_faq_details'         => is_string( $article_block_blocks_json ) && false !== strpos( $article_block_blocks_json, '"blockName":"core\\/details"' ),
		'has_mobile_stack_attrs'  => is_string( $article_block_blocks_json ) && false !== strpos( $article_block_blocks_json, '"isStackedOnMobile":true' ),
		'has_complete_images'     => true,
		'padded_block_count'      => maa_adapter_smoke_count_padded_blocks( $article_block_blocks ),
	)
);
maa_adapter_smoke_assert( '' !== (string) ( $article_block_visual_fixture['front_end_url'] ?? '' ), 'adapter article block visual acceptance fixture has front-end URL' );
maa_adapter_smoke_assert( '' !== (string) ( $article_block_visual_fixture['block_editor_url'] ?? '' ), 'adapter article block visual acceptance fixture has block editor URL' );
maa_adapter_smoke_assert( 3 === count( (array) ( $article_block_visual_fixture['viewports'] ?? array() ) ), 'adapter article block visual acceptance fixture has three viewport targets' );

$media_optimization_attachment_id = maa_adapter_smoke_create_real_media_attachment();
$media_optimization_original_relative = (string) get_post_meta( $media_optimization_attachment_id, '_wp_attached_file', true );
$media_optimization_original_uploads  = wp_upload_dir();
$media_optimization_original_path     = is_array( $media_optimization_original_uploads ) ? trailingslashit( (string) ( $media_optimization_original_uploads['basedir'] ?? '' ) ) . ltrim( $media_optimization_original_relative, '/' ) : '';
$media_optimization_original_contents = '' !== $media_optimization_original_path && is_readable( $media_optimization_original_path ) ? (string) file_get_contents( $media_optimization_original_path ) : '';
$media_optimization_artifact_id = 'adapter-smoke-webp-artifact-' . substr( wp_generate_uuid4(), 0, 8 );
$media_optimization_artifact_contents = 'adapter-smoke-webp-derivative-bytes';
$media_optimization_removed_payload_route = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/media-derivative-proposal-payload',
	maa_adapter_smoke_media_optimization_payload_params( $media_optimization_attachment_id, $media_optimization_artifact_id . '-missing-details', $media_optimization_artifact_contents, false )
);
$media_optimization_removed_payload_data = is_array( $media_optimization_removed_payload_route['data'] ) ? $media_optimization_removed_payload_route['data'] : array();
maa_adapter_smoke_assert( 404 === (int) $media_optimization_removed_payload_route['status'], 'adapter media derivative proposal payload route is removed' );
maa_adapter_smoke_assert( 'rest_no_route' === (string) ( $media_optimization_removed_payload_data['code'] ?? '' ), 'removed media derivative proposal payload route returns rest_no_route' );
if ( false ) :
	$media_optimization_missing_details_payload = array();
maa_adapter_smoke_assert( false === (bool) ( $media_optimization_missing_details_payload['proposal_ready'] ?? true ), 'adapter media optimization payload is not proposal-ready without metadata input' );
maa_adapter_smoke_assert( empty( $media_optimization_missing_details_payload['from_plan_request'] ?? array() ), 'adapter media optimization payload omits from_plan_request until metadata is reviewed' );
maa_adapter_smoke_assert( in_array( 'media_details_input', (array) ( $media_optimization_missing_details_payload['media_optimization_plan']['requires_input'] ?? array() ), true ), 'adapter media optimization payload identifies missing metadata input' );
maa_adapter_smoke_assert( false !== strpos( (string) ( $media_optimization_missing_details_payload['next_step'] ?? '' ), '/proposals/from-plan' ), 'adapter media optimization missing-input next step still points back to from-plan' );
$media_optimization_payload = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/media-derivative-proposal-payload',
	maa_adapter_smoke_media_optimization_payload_params( $media_optimization_attachment_id, $media_optimization_artifact_id, $media_optimization_artifact_contents, true )
);
maa_adapter_smoke_assert( true === (bool) ( $media_optimization_payload['proposal_ready'] ?? false ), 'adapter media derivative payload is ready for one optimization proposal' );
maa_adapter_smoke_assert( 'POST /proposals/from-plan' === (string) ( $media_optimization_payload['preferred_core_route'] ?? '' ), 'adapter media derivative payload prefers Core from-plan route' );
maa_adapter_smoke_assert( 'surface_plan_ability_unavailable_do_not_split_into_two_proposals' === (string) ( $media_optimization_payload['ability_guard']['missing_capability_behavior'] ?? '' ), 'adapter media derivative payload exposes missing-capability guard' );
$media_optimization_from_plan = is_array( $media_optimization_payload['from_plan_request'] ?? null ) ? $media_optimization_payload['from_plan_request'] : array();
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/build-media-optimization-plan' === (string) ( $media_optimization_from_plan['plan_ability_id'] ?? '' ), 'adapter media derivative payload returns media optimization from_plan_request' );
$media_optimization_plan = is_array( $media_optimization_from_plan['plan'] ?? null ) ? $media_optimization_from_plan['plan'] : array();
maa_adapter_smoke_assert( 'media_optimization_plan' === (string) ( $media_optimization_plan['artifact_type'] ?? '' ), 'adapter media derivative payload returns media optimization plan artifact' );
maa_adapter_smoke_assert( true === (bool) ( $media_optimization_plan['batch_approval'] ?? false ), 'adapter media optimization plan requests one batch approval' );
maa_adapter_smoke_assert( 2 === count( (array) ( $media_optimization_plan['write_actions'] ?? array() ) ), 'adapter media optimization plan includes two write actions' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/update-media-details' === (string) ( $media_optimization_plan['write_actions'][0]['target_ability_id'] ?? '' ), 'adapter media optimization plan starts with metadata update' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/adopt-cloud-media-derivative' === (string) ( $media_optimization_plan['write_actions'][1]['target_ability_id'] ?? '' ), 'adapter media optimization plan includes derivative adoption' );
$media_optimization_repairs_payload = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/media-derivative-proposal-payload',
	maa_adapter_smoke_media_optimization_payload_params( $media_optimization_attachment_id, $media_optimization_artifact_id . '-repairs', $media_optimization_artifact_contents, true, true )
);
$media_optimization_repairs_from_plan = is_array( $media_optimization_repairs_payload['from_plan_request'] ?? null ) ? $media_optimization_repairs_payload['from_plan_request'] : array();
$media_optimization_repairs_plan = is_array( $media_optimization_repairs_from_plan['plan'] ?? null ) ? $media_optimization_repairs_from_plan['plan'] : array();
maa_adapter_smoke_assert( 2 === count( (array) ( $media_optimization_repairs_plan['write_actions'] ?? array() ) ), 'adapter media optimization plan keeps content reference repairs inside derivative adoption' );
maa_adapter_smoke_assert( array( 4312 ) === (array) ( $media_optimization_repairs_plan['write_actions'][1]['input']['expected_content_reference_post_ids'] ?? array() ), 'adapter media optimization adoption input carries reviewed reference repair post ids' );
maa_adapter_smoke_assert( 1 === (int) ( $media_optimization_repairs_plan['write_actions'][1]['input']['expected_content_reference_post_count'] ?? -1 ), 'adapter media optimization adoption input carries reviewed reference repair post count' );
maa_adapter_smoke_assert( 1 === (int) ( $media_optimization_repairs_plan['write_actions'][1]['input']['expected_content_reference_replacement_count'] ?? -1 ), 'adapter media optimization adoption input carries reviewed reference repair replacement count' );
maa_adapter_smoke_assert( ! in_array( 'npcink-abilities-toolkit/patch-post-content', (array) ( $media_optimization_repairs_plan['target_ability_ids'] ?? array() ), true ), 'adapter media optimization target ability ids do not split patch-post-content repair action' );
$media_optimization_repairs_bridge_result = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals/from-plan',
	array_merge(
		$media_optimization_repairs_from_plan,
		array(
			'plan_input'         => array(
				'attachment_id' => $media_optimization_attachment_id,
				'source_type'   => 'ai_generated',
			),
			'adapter_request_id' => 'adapter-media-optimization-repairs-request',
			'correlation_id'     => 'adapter-media-optimization-repairs-correlation',
			'caller'             => array(
				'external_thread_id' => 'adapter-media-optimization-repairs-smoke',
			),
		)
	)
);
$media_optimization_repairs_bridge = is_array( $media_optimization_repairs_bridge_result['data'] ) ? $media_optimization_repairs_bridge_result['data'] : array();
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_plan_action_input_invalid' !== (string) ( $media_optimization_repairs_bridge['code'] ?? '' ), 'adapter accepts content reference expectation fields in media optimization plan actions' );
if ( $media_optimization_repairs_bridge_result['status'] >= 200 && $media_optimization_repairs_bridge_result['status'] < 300 ) {
	$media_optimization_repairs_proposal = is_array( $media_optimization_repairs_bridge['proposals'][0] ?? null ) ? $media_optimization_repairs_bridge['proposals'][0] : array();
	$media_optimization_repairs_proposal_id = (string) ( $media_optimization_repairs_proposal['proposal_id'] ?? '' );
	if ( '' !== $media_optimization_repairs_proposal_id ) {
		$maa_adapter_smoke_cleanup_proposal_ids[] = $media_optimization_repairs_proposal_id;
	}
}

$media_optimization_bridge_result = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals/from-plan',
	array_merge(
		$media_optimization_from_plan,
		array(
			'plan_input'         => array(
				'attachment_id' => $media_optimization_attachment_id,
				'source_type'   => 'ai_generated',
			),
			'adapter_request_id' => 'adapter-media-optimization-request',
			'correlation_id'     => 'adapter-media-optimization-correlation',
			'caller'             => array(
				'external_thread_id' => 'adapter-media-optimization-smoke',
			),
		)
	)
);
$media_optimization_bridge = is_array( $media_optimization_bridge_result['data'] ) ? $media_optimization_bridge_result['data'] : array();
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_plan_ability_unsupported' !== (string) ( $media_optimization_bridge['code'] ?? '' ), 'adapter does not reject media optimization plan at Adapter supported profiles' );
if ( 404 === (int) $media_optimization_bridge_result['status'] && 'npcink_governance_core_plan_ability_unavailable' === (string) ( $media_optimization_bridge['code'] ?? '' ) ) {
	maa_adapter_smoke_assert( '/npcink-governance-core/v1/proposals/from-plan' === (string) ( $media_optimization_bridge['data']['upstream_route'] ?? '' ), 'adapter forwards media optimization plan to Core when local Core catalog lacks the planning ability' );
} else {
	maa_adapter_smoke_assert( $media_optimization_bridge_result['status'] >= 200 && $media_optimization_bridge_result['status'] < 300, 'POST /npcink-openclaw-adapter/v1/proposals/from-plan accepts media optimization plan' );
	maa_adapter_smoke_assert( 1 === (int) ( $media_optimization_bridge['proposal_count'] ?? 0 ), 'adapter media optimization plan creates one Core batch proposal' );
	$media_optimization_proposal = is_array( $media_optimization_bridge['proposals'][0] ?? null ) ? $media_optimization_bridge['proposals'][0] : array();
	$media_optimization_proposal_id = (string) ( $media_optimization_proposal['proposal_id'] ?? '' );
	$maa_adapter_smoke_cleanup_proposal_ids[] = $media_optimization_proposal_id;
	maa_adapter_smoke_assert( 'plan_to_proposal_batch' === (string) ( $media_optimization_proposal['preview']['source']['type'] ?? '' ), 'adapter media optimization Core proposal stores batch source' );
	maa_adapter_smoke_assert( 2 === count( (array) ( $media_optimization_proposal['input']['write_actions'] ?? array() ) ), 'adapter media optimization Core proposal stores metadata and derivative actions' );
	$media_optimization_approved = maa_adapter_smoke_rest(
		'POST',
		'/npcink-governance-core/v1/proposals/' . rawurlencode( $media_optimization_proposal_id ) . '/approve',
		array(
			'note' => 'Approve adapter media optimization smoke batch execution.',
		)
	);
	maa_adapter_smoke_assert( 'approved' === (string) ( $media_optimization_approved['status'] ?? '' ), 'Core admin REST approval succeeds for media optimization batch proposal' );
	$media_optimization_preflight = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $media_optimization_proposal_id ) . '/commit-preflight' );
	maa_adapter_smoke_assert( false === (bool) ( $media_optimization_preflight['commit_execution'] ?? true ), 'adapter media optimization preflight keeps Core commit_execution=false' );
	maa_adapter_smoke_assert( true === (bool) ( $media_optimization_preflight['adapter_preflight_handoff_cached'] ?? false ), 'adapter media optimization preflight caches execution handoff' );
	$media_optimization_execute = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $media_optimization_proposal_id ) . '/execute' );
	maa_adapter_smoke_assert( 'executed' === (string) ( $media_optimization_execute['status'] ?? '' ), 'adapter media optimization execute succeeds after one Core batch approval' );
	maa_adapter_smoke_assert( 'batch_write_actions' === (string) ( $media_optimization_execute['execution_mode'] ?? '' ), 'adapter media optimization executes as batch write_actions' );
	maa_adapter_smoke_assert( 2 === (int) ( $media_optimization_execute['executed_count'] ?? 0 ), 'adapter media optimization executes metadata and derivative actions together' );
	$media_optimization_execution_record = is_array( $media_optimization_execute['execution_record'] ?? null ) ? $media_optimization_execute['execution_record'] : array();
	$media_optimization_verification = is_array( $media_optimization_execution_record['verification'] ?? null ) ? $media_optimization_execution_record['verification'] : array();
	maa_adapter_smoke_assert( 'recorded' === (string) ( $media_optimization_verification['status'] ?? '' ), 'adapter media optimization execution record persists compact verification' );
	maa_adapter_smoke_assert( true === (bool) ( $media_optimization_verification['aggregates']['backup_available'] ?? false ), 'adapter media optimization verification confirms backup availability' );
	maa_adapter_smoke_assert( true === (bool) ( $media_optimization_verification['aggregates']['rollback_available'] ?? false ), 'adapter media optimization verification confirms rollback availability' );
	maa_adapter_smoke_assert( 'Adapter media optimization smoke' === (string) get_the_title( $media_optimization_attachment_id ), 'adapter media optimization batch updates media title' );
	maa_adapter_smoke_assert( 'Adapter media optimization smoke image' === (string) get_post_meta( $media_optimization_attachment_id, '_wp_attachment_image_alt', true ), 'adapter media optimization batch updates media alt text' );
	maa_adapter_smoke_assert( 'ai_generated' === (string) get_post_meta( $media_optimization_attachment_id, '_npcink_ai_media_source_type', true ), 'adapter media optimization batch updates media source type' );
	maa_adapter_smoke_assert( 'image/webp' === (string) get_post_mime_type( $media_optimization_attachment_id ), 'adapter media optimization batch adopts WebP mime type' );
	$media_optimization_after_relative = (string) get_post_meta( $media_optimization_attachment_id, '_wp_attached_file', true );
	$media_optimization_after_uploads  = wp_upload_dir();
	$media_optimization_after_path     = is_array( $media_optimization_after_uploads ) ? trailingslashit( (string) ( $media_optimization_after_uploads['basedir'] ?? '' ) ) . ltrim( $media_optimization_after_relative, '/' ) : '';
	maa_adapter_smoke_assert( false !== strpos( $media_optimization_after_relative, '.webp' ), 'adapter media optimization batch points attachment at WebP file' );
	maa_adapter_smoke_assert( '' !== $media_optimization_after_path && is_readable( $media_optimization_after_path ), 'adapter media optimization batch writes adopted WebP file' );
	maa_adapter_smoke_assert( $media_optimization_artifact_contents === (string) file_get_contents( $media_optimization_after_path ), 'adapter media optimization batch writes expected Cloud artifact bytes' );
	$media_optimization_executed_detail = maa_adapter_smoke_rest( 'GET', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $media_optimization_proposal_id ) );
	maa_adapter_smoke_assert( 'executed' === (string) ( $media_optimization_executed_detail['status'] ?? '' ), 'adapter media optimization detail records Core executed status' );
	maa_adapter_smoke_assert( 'executed' === (string) ( $media_optimization_executed_detail['effective_status'] ?? '' ), 'adapter media optimization detail exposes executed effective status' );
	maa_adapter_smoke_assert( 'recorded' === (string) ( $media_optimization_executed_detail['adapter_status']['execution_record']['verification']['status'] ?? '' ), 'adapter media optimization detail exposes persisted verification summary' );
	maa_adapter_smoke_assert( true === (bool) ( $media_optimization_executed_detail['adapter_status']['execution_record']['core_execution_record']['recorded'] ?? false ), 'adapter media optimization detail records Core execution outcome' );
	$media_optimization_replacement_id = '';
	foreach ( (array) ( $media_optimization_execute['results'] ?? array() ) as $media_optimization_result ) {
		$result_payload = is_array( $media_optimization_result['result'] ?? null ) ? $media_optimization_result['result'] : array();
		if ( 'npcink-abilities-toolkit/adopt-cloud-media-derivative' === (string) ( $media_optimization_result['target_ability_id'] ?? '' ) ) {
			$media_optimization_replacement_id = (string) ( $result_payload['replacement_id'] ?? '' );
			break;
		}
	}
	maa_adapter_smoke_assert( '' !== $media_optimization_replacement_id, 'adapter media optimization execution returns replacement id for restore' );
	$media_restore_proposal = maa_adapter_smoke_rest(
		'POST',
		'/npcink-openclaw-adapter/v1/proposals',
		array(
			'ability_id' => 'npcink-abilities-toolkit/restore-media-backup',
			'title'      => 'Adapter restore media backup smoke',
			'summary'    => 'Restore the media optimization smoke fixture from its recorded backup.',
			'input'      => array(
				'attachment_id'                  => $media_optimization_attachment_id,
				'backup_id'                      => $media_optimization_replacement_id,
				'expected_current_relative_file' => $media_optimization_after_relative,
				'expected_current_mime_type'     => 'image/webp',
				'target_conflict_mode'           => 'overwrite',
				'dry_run'                        => true,
				'commit'                         => false,
			),
			'preview'    => array(
				'dry_run' => true,
				'commit'  => false,
			),
		)
	);
	$media_restore_proposal_id = (string) ( $media_restore_proposal['proposal_id'] ?? '' );
	$maa_adapter_smoke_cleanup_proposal_ids[] = $media_restore_proposal_id;
	maa_adapter_smoke_assert( '' !== $media_restore_proposal_id, 'adapter creates restore-media-backup proposal for media rollback smoke' );
	$media_restore_execute = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $media_restore_proposal_id ) . '/approve-and-execute' );
	maa_adapter_smoke_assert( true === (bool) ( $media_restore_execute['success'] ?? false ), 'adapter restore-media-backup approve-and-execute succeeds' );
	maa_adapter_smoke_assert( 'npcink-abilities-toolkit/restore-media-backup' === (string) ( $media_restore_execute['ability_id'] ?? '' ), 'adapter restore execution response carries restore ability id' );
	$media_restore_execution = is_array( $media_restore_execute['execution'] ?? null ) ? $media_restore_execute['execution'] : array();
	$media_restore_result = is_array( $media_restore_execution['result'] ?? null ) ? $media_restore_execution['result'] : array();
	maa_adapter_smoke_assert( true === (bool) ( $media_restore_result['restored'] ?? false ), 'adapter restore-media-backup ability reports restored' );
	maa_adapter_smoke_assert( true === (bool) ( $media_restore_result['rolled_back'] ?? false ), 'adapter restore-media-backup ability reports rolled back' );
	$media_restore_record = is_array( $media_restore_execute['execution_record'] ?? null ) ? $media_restore_execute['execution_record'] : array();
	$media_restore_verification = is_array( $media_restore_record['verification'] ?? null ) ? $media_restore_record['verification'] : array();
	maa_adapter_smoke_assert( 'recorded' === (string) ( $media_restore_verification['status'] ?? '' ), 'adapter restore execution record persists compact verification' );
	maa_adapter_smoke_assert( true === (bool) ( $media_restore_verification['aggregates']['backup_available'] ?? false ), 'adapter restore verification confirms current backup availability' );
	maa_adapter_smoke_assert( true === (bool) ( $media_restore_verification['aggregates']['rollback_available'] ?? false ), 'adapter restore verification confirms rollback availability' );
	$media_restore_after_relative = (string) get_post_meta( $media_optimization_attachment_id, '_wp_attached_file', true );
	$media_restore_after_path = is_array( $media_optimization_original_uploads ) ? trailingslashit( (string) ( $media_optimization_original_uploads['basedir'] ?? '' ) ) . ltrim( $media_restore_after_relative, '/' ) : '';
	maa_adapter_smoke_assert( $media_optimization_original_relative === $media_restore_after_relative, 'adapter restore-media-backup restores original attached file pointer' );
	maa_adapter_smoke_assert( 'image/png' === (string) get_post_mime_type( $media_optimization_attachment_id ), 'adapter restore-media-backup restores original mime type' );
	maa_adapter_smoke_assert( '' !== $media_restore_after_path && is_readable( $media_restore_after_path ), 'adapter restore-media-backup writes restored media file' );
	maa_adapter_smoke_assert( $media_optimization_original_contents === (string) file_get_contents( $media_restore_after_path ), 'adapter restore-media-backup restores original media bytes' );
}

$multi_media_optimization_attachment_ids = array(
	maa_adapter_smoke_create_real_media_attachment( 'adapter-media-batch-a-' ),
	maa_adapter_smoke_create_real_media_attachment( 'adapter-media-batch-b-' ),
);
$maa_adapter_smoke_cleanup_attachment_ids = array_merge( $maa_adapter_smoke_cleanup_attachment_ids, $multi_media_optimization_attachment_ids );
$multi_media_optimization_plan = array(
	'artifact_type'      => 'media_optimization_plan',
	'version'            => 1,
	'batch_id'           => 'adapter_multi_media_optimization_' . substr( wp_generate_uuid4(), 0, 8 ),
	'attachment_ids'     => $multi_media_optimization_attachment_ids,
	'optimization_goal'  => 'multi_attachment_image_seo_and_derivative_adoption',
	'requires_approval'  => true,
	'dry_run'            => true,
	'commit_execution'   => false,
	'proposal_mode'      => 'batch',
	'batch_approval'     => true,
	'action_count'       => 0,
	'action_ids'         => array(),
	'target_ability_ids' => array(),
	'preview'            => array(),
	'write_actions'      => array(),
	'proposal_ready'     => true,
	'risk'               => array(
		'level'  => 'medium',
		'reason' => 'Two reviewed media optimization fixtures share one Core batch approval.',
	),
);
$multi_media_optimization_expected_artifacts = array();
foreach ( $multi_media_optimization_attachment_ids as $multi_media_optimization_index => $multi_media_optimization_attachment_id ) {
	$multi_media_optimization_artifact_contents = 'adapter-smoke-multi-webp-derivative-bytes-' . ( $multi_media_optimization_index + 1 );
	$multi_media_optimization_artifact_id       = 'adapter-smoke-multi-webp-artifact-' . ( $multi_media_optimization_index + 1 ) . '-' . substr( wp_generate_uuid4(), 0, 8 );
	$multi_media_optimization_payload = maa_adapter_smoke_rest(
		'POST',
		'/npcink-openclaw-adapter/v1/media-derivative-proposal-payload',
		maa_adapter_smoke_media_optimization_payload_params( $multi_media_optimization_attachment_id, $multi_media_optimization_artifact_id, $multi_media_optimization_artifact_contents, true )
	);
	$multi_media_optimization_from_plan = is_array( $multi_media_optimization_payload['from_plan_request'] ?? null ) ? $multi_media_optimization_payload['from_plan_request'] : array();
	$single_media_optimization_plan     = is_array( $multi_media_optimization_from_plan['plan'] ?? null ) ? $multi_media_optimization_from_plan['plan'] : array();
	$single_media_optimization_actions  = array_values( (array) ( $single_media_optimization_plan['write_actions'] ?? array() ) );
	maa_adapter_smoke_assert( 2 === count( $single_media_optimization_actions ), 'adapter multi media optimization source plan has two write actions per attachment' );
	foreach ( $single_media_optimization_actions as $single_media_optimization_action ) {
		$multi_media_optimization_plan['write_actions'][] = $single_media_optimization_action;
		$multi_media_optimization_plan['action_ids'][] = (string) ( $single_media_optimization_action['action_id'] ?? '' );
	}
	$multi_media_optimization_expected_artifacts[ $multi_media_optimization_attachment_id ] = $multi_media_optimization_artifact_contents;
}
$multi_media_optimization_plan['action_count'] = count( $multi_media_optimization_plan['write_actions'] );
$multi_media_optimization_plan['target_ability_ids'] = array_values(
	array_unique(
		array_filter(
			array_map(
				static function ( $action ) {
					return is_array( $action ) ? (string) ( $action['target_ability_id'] ?? '' ) : '';
				},
				$multi_media_optimization_plan['write_actions']
			)
		)
	)
);
maa_adapter_smoke_assert( 4 === (int) $multi_media_optimization_plan['action_count'], 'adapter multi media optimization plan combines four write actions' );
$multi_media_optimization_bridge_result = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals/from-plan',
	array(
		'plan_ability_id'    => 'npcink-abilities-toolkit/build-media-optimization-plan',
		'plan'               => $multi_media_optimization_plan,
		'plan_input'         => array(
			'attachment_ids' => $multi_media_optimization_attachment_ids,
			'source_type'    => 'ai_generated',
		),
		'adapter_request_id' => 'adapter-multi-media-optimization-request',
		'correlation_id'     => 'adapter-multi-media-optimization-correlation',
		'caller'             => array(
			'external_thread_id' => 'adapter-multi-media-optimization-smoke',
		),
	)
);
$multi_media_optimization_bridge = is_array( $multi_media_optimization_bridge_result['data'] ) ? $multi_media_optimization_bridge_result['data'] : array();
maa_adapter_smoke_assert( $multi_media_optimization_bridge_result['status'] >= 200 && $multi_media_optimization_bridge_result['status'] < 300, 'POST /npcink-openclaw-adapter/v1/proposals/from-plan accepts multi media optimization plan' );
maa_adapter_smoke_assert( 1 === (int) ( $multi_media_optimization_bridge['proposal_count'] ?? 0 ), 'adapter multi media optimization creates one Core batch proposal' );
$multi_media_optimization_proposal = is_array( $multi_media_optimization_bridge['proposals'][0] ?? null ) ? $multi_media_optimization_bridge['proposals'][0] : array();
$multi_media_optimization_proposal_id = (string) ( $multi_media_optimization_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $multi_media_optimization_proposal_id;
maa_adapter_smoke_assert( '' !== $multi_media_optimization_proposal_id, 'adapter multi media optimization returned a Core proposal id' );
maa_adapter_smoke_assert( 4 === count( (array) ( $multi_media_optimization_proposal['input']['write_actions'] ?? array() ) ), 'adapter multi media optimization Core proposal stores four actions' );
$multi_media_optimization_approved = maa_adapter_smoke_rest(
	'POST',
	'/npcink-governance-core/v1/proposals/' . rawurlencode( $multi_media_optimization_proposal_id ) . '/approve',
	array(
		'note' => 'Approve adapter multi media optimization smoke batch execution.',
	)
);
maa_adapter_smoke_assert( 'approved' === (string) ( $multi_media_optimization_approved['status'] ?? '' ), 'Core admin REST approval succeeds for multi media optimization batch proposal' );
$multi_media_optimization_execute = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $multi_media_optimization_proposal_id ) . '/execute' );
maa_adapter_smoke_assert( 'executed' === (string) ( $multi_media_optimization_execute['status'] ?? '' ), 'adapter multi media optimization execute succeeds after one Core batch approval' );
maa_adapter_smoke_assert( 'batch_write_actions' === (string) ( $multi_media_optimization_execute['execution_mode'] ?? '' ), 'adapter multi media optimization executes as batch write_actions' );
maa_adapter_smoke_assert( 4 === (int) ( $multi_media_optimization_execute['executed_count'] ?? 0 ), 'adapter multi media optimization executes four actions together' );
$multi_media_optimization_record = is_array( $multi_media_optimization_execute['execution_record'] ?? null ) ? $multi_media_optimization_execute['execution_record'] : array();
$multi_media_optimization_verification = is_array( $multi_media_optimization_record['verification'] ?? null ) ? $multi_media_optimization_record['verification'] : array();
maa_adapter_smoke_assert( 'recorded' === (string) ( $multi_media_optimization_verification['status'] ?? '' ), 'adapter multi media optimization persists compact verification' );
maa_adapter_smoke_assert( 2 === (int) ( $multi_media_optimization_verification['item_count'] ?? 0 ), 'adapter multi media optimization verification records two derivative adoption items' );
foreach ( $multi_media_optimization_expected_artifacts as $multi_media_optimization_attachment_id => $multi_media_optimization_artifact_contents ) {
	$multi_media_optimization_after_relative = (string) get_post_meta( (int) $multi_media_optimization_attachment_id, '_wp_attached_file', true );
	$multi_media_optimization_after_uploads  = wp_upload_dir();
	$multi_media_optimization_after_path     = is_array( $multi_media_optimization_after_uploads ) ? trailingslashit( (string) ( $multi_media_optimization_after_uploads['basedir'] ?? '' ) ) . ltrim( $multi_media_optimization_after_relative, '/' ) : '';
	maa_adapter_smoke_assert( 'image/webp' === (string) get_post_mime_type( (int) $multi_media_optimization_attachment_id ), 'adapter multi media optimization adopts WebP mime type for each attachment' );
	maa_adapter_smoke_assert( '' !== $multi_media_optimization_after_path && is_readable( $multi_media_optimization_after_path ), 'adapter multi media optimization writes each adopted WebP file' );
	maa_adapter_smoke_assert( $multi_media_optimization_artifact_contents === (string) file_get_contents( $multi_media_optimization_after_path ), 'adapter multi media optimization writes expected artifact bytes for each attachment' );
}

$checksum_mismatch_attachment_id = maa_adapter_smoke_create_real_media_attachment( 'adapter-checksum-mismatch-' );
$maa_adapter_smoke_cleanup_attachment_ids[] = $checksum_mismatch_attachment_id;
$checksum_mismatch_before_relative = (string) get_post_meta( $checksum_mismatch_attachment_id, '_wp_attached_file', true );
$checksum_mismatch_payload = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/media-derivative-proposal-payload',
	maa_adapter_smoke_media_optimization_payload_params(
		$checksum_mismatch_attachment_id,
		'adapter-smoke-checksum-mismatch-' . substr( wp_generate_uuid4(), 0, 8 ),
		'adapter-smoke-checksum-mismatch-actual-bytes',
		true
	)
);
$checksum_mismatch_from_plan = is_array( $checksum_mismatch_payload['from_plan_request'] ?? null ) ? $checksum_mismatch_payload['from_plan_request'] : array();
$checksum_mismatch_plan = is_array( $checksum_mismatch_from_plan['plan'] ?? null ) ? $checksum_mismatch_from_plan['plan'] : array();
$checksum_mismatch_plan['write_actions'][1]['input']['derivative_artifact']['sha256'] = str_repeat( '0', 64 );
$checksum_mismatch_plan['write_actions'][1]['input']['derivative_artifact']['checksum'] = 'sha256:' . str_repeat( '0', 64 );
$checksum_mismatch_bridge = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals/from-plan',
	array(
		'plan_ability_id'    => 'npcink-abilities-toolkit/build-media-optimization-plan',
		'plan'               => $checksum_mismatch_plan,
		'plan_input'         => array(
			'attachment_id' => $checksum_mismatch_attachment_id,
			'source_type'   => 'ai_generated',
		),
		'adapter_request_id' => 'adapter-media-checksum-mismatch-request',
		'correlation_id'     => 'adapter-media-checksum-mismatch-correlation',
		'caller'             => array(
			'external_thread_id' => 'adapter-media-checksum-mismatch-smoke',
		),
	)
);
$checksum_mismatch_proposal = is_array( $checksum_mismatch_bridge['proposals'][0] ?? null ) ? $checksum_mismatch_bridge['proposals'][0] : array();
$checksum_mismatch_proposal_id = (string) ( $checksum_mismatch_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $checksum_mismatch_proposal_id;
maa_adapter_smoke_assert( '' !== $checksum_mismatch_proposal_id, 'adapter checksum mismatch media optimization creates a Core batch proposal' );
maa_adapter_smoke_rest(
	'POST',
	'/npcink-governance-core/v1/proposals/' . rawurlencode( $checksum_mismatch_proposal_id ) . '/approve',
	array(
		'note' => 'Approve adapter checksum mismatch media optimization smoke.',
	)
);
$checksum_mismatch_execute = maa_adapter_smoke_rest_result( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $checksum_mismatch_proposal_id ) . '/execute' );
maa_adapter_smoke_assert( 409 === (int) $checksum_mismatch_execute['status'], 'adapter media optimization checksum mismatch fails during execution' );
$checksum_mismatch_error = is_array( $checksum_mismatch_execute['data'] ) ? $checksum_mismatch_execute['data'] : array();
$checksum_mismatch_error_data = is_array( $checksum_mismatch_error['data'] ?? null ) ? $checksum_mismatch_error['data'] : array();
maa_adapter_smoke_assert( 'npcink_abilities_toolkit_cloud_artifact_checksum_mismatch' === (string) ( $checksum_mismatch_error['code'] ?? '' ), 'adapter media optimization checksum mismatch returns stable ability error code' );
maa_adapter_smoke_assert( (string) ( $checksum_mismatch_plan['write_actions'][1]['action_id'] ?? '' ) === (string) ( $checksum_mismatch_error_data['action_id'] ?? '' ), 'adapter media optimization checksum mismatch identifies failed action id' );
maa_adapter_smoke_assert( 1 === count( (array) ( $checksum_mismatch_error_data['executed_results'] ?? array() ) ), 'adapter media optimization checksum mismatch reports already executed metadata action' );
maa_adapter_smoke_assert( 'failed' === (string) ( $checksum_mismatch_error_data['execution_record']['status'] ?? '' ), 'adapter media optimization checksum mismatch stores failed execution record' );
maa_adapter_smoke_assert( 1 === (int) ( $checksum_mismatch_error_data['execution_record']['executed_count'] ?? 0 ), 'adapter media optimization checksum mismatch execution record counts partial success' );
maa_adapter_smoke_assert( 1 === (int) ( $checksum_mismatch_error_data['execution_record']['failed_count'] ?? 0 ), 'adapter media optimization checksum mismatch execution record counts failure' );
maa_adapter_smoke_assert( $checksum_mismatch_before_relative === (string) get_post_meta( $checksum_mismatch_attachment_id, '_wp_attached_file', true ), 'adapter media optimization checksum mismatch leaves attachment file pointer unchanged' );
endif;

$expected_file_mismatch_attachment_id = maa_adapter_smoke_create_real_media_attachment( 'adapter-current-file-mismatch-' );
$maa_adapter_smoke_cleanup_attachment_ids[] = $expected_file_mismatch_attachment_id;
$expected_file_mismatch_before_relative = (string) get_post_meta( $expected_file_mismatch_attachment_id, '_wp_attached_file', true );
$expected_file_mismatch_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/rename-media-file',
		'title'      => 'Adapter expected current file mismatch smoke',
		'summary'    => 'Adapter must surface current file mismatch failures from the media executor.',
		'input'      => array(
			'attachment_id'                  => $expected_file_mismatch_attachment_id,
			'target_file_name'               => 'adapter-current-file-mismatch.webp',
			'expected_current_relative_file' => '2026/06/definitely-not-current-file.png',
			'expected_current_mime_type'     => 'image/png',
			'conflict_mode'                  => 'fail',
			'dry_run'                        => true,
			'commit'                         => false,
		),
		'preview'    => array(
			'action' => 'rename_media_file_expected_current_file_mismatch',
		),
	)
);
$expected_file_mismatch_proposal_id = (string) ( $expected_file_mismatch_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $expected_file_mismatch_proposal_id;
maa_adapter_smoke_assert( '' !== $expected_file_mismatch_proposal_id, 'adapter expected current file mismatch creates a Core proposal' );
$expected_file_mismatch_execute = maa_adapter_smoke_rest_result( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $expected_file_mismatch_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( 409 === (int) $expected_file_mismatch_execute['status'], 'adapter expected current file mismatch fails during execution' );
$expected_file_mismatch_error = is_array( $expected_file_mismatch_execute['data'] ) ? $expected_file_mismatch_execute['data'] : array();
$expected_file_mismatch_error_data = is_array( $expected_file_mismatch_error['data'] ?? null ) ? $expected_file_mismatch_error['data'] : array();
maa_adapter_smoke_assert( 'npcink_abilities_toolkit_current_file_mismatch' === (string) ( $expected_file_mismatch_error['code'] ?? '' ), 'adapter expected current file mismatch returns stable ability error code' );
maa_adapter_smoke_assert( 'failed' === (string) ( $expected_file_mismatch_error_data['execution_record']['status'] ?? '' ), 'adapter expected current file mismatch stores failed execution record' );
maa_adapter_smoke_assert( $expected_file_mismatch_before_relative === (string) get_post_meta( $expected_file_mismatch_attachment_id, '_wp_attached_file', true ), 'adapter expected current file mismatch leaves attachment file pointer unchanged' );

$site_summary = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id' => 'npcink-abilities-toolkit/site-info',
		'input'      => array(),
	)
);
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/site-info' === (string) ( $site_summary['ability_id'] ?? '' ), 'adapter runs site-info read ability' );
maa_adapter_smoke_assert( is_array( $site_summary['result'] ?? null ), 'site-summary returns a result object' );
maa_adapter_smoke_assert( 'direct_read_public' === (string) ( $site_summary['read_policy'] ?? '' ), 'adapter site-summary read carries public read policy' );
maa_adapter_smoke_assert( '' !== (string) ( $site_summary['correlation_id'] ?? '' ), 'adapter read response carries generated correlation id' );

$discoverability_brief_response = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id' => 'npcink-toolbox/build-content-discoverability-brief',
		'input'      => array(
			'topic' => 'WordPress AI SEO GEO AEO smoke',
			'title' => 'WordPress AI SEO GEO AEO smoke',
		),
	)
);
$discoverability_brief = is_array( $discoverability_brief_response['result']['data'] ?? null ) ? $discoverability_brief_response['result']['data'] : ( is_array( $discoverability_brief_response['result'] ?? null ) ? $discoverability_brief_response['result'] : array() );
maa_adapter_smoke_assert( 'npcink-toolbox/build-content-discoverability-brief' === (string) ( $discoverability_brief_response['ability_id'] ?? '' ), 'adapter runs content discoverability brief primary ability' );
maa_adapter_smoke_assert( 'content_discoverability_brief' === (string) ( $discoverability_brief['artifact_type'] ?? '' ), 'content discoverability brief returns the expected artifact type' );
maa_adapter_smoke_assert( true === (bool) ( $discoverability_brief['primary_contract'] ?? false ), 'content discoverability brief is marked as the primary SEO/GEO/AEO contract' );
maa_adapter_smoke_assert( 'suggestion_only' === (string) ( $discoverability_brief['write_posture'] ?? '' ), 'content discoverability brief is suggestion-only' );
maa_adapter_smoke_assert( 'core_proposal_required' === (string) ( $discoverability_brief['final_write_path'] ?? '' ), 'content discoverability brief points final writes to Core proposals' );
maa_adapter_smoke_assert( false === (bool) ( $discoverability_brief['direct_wordpress_write'] ?? true ), 'content discoverability brief disables direct WordPress writes' );
maa_adapter_smoke_assert( is_array( $discoverability_brief['seo'] ?? null ), 'content discoverability brief exposes SEO section' );
maa_adapter_smoke_assert( is_array( $discoverability_brief['aeo'] ?? null ), 'content discoverability brief exposes AEO section' );
maa_adapter_smoke_assert( is_array( $discoverability_brief['geo'] ?? null ), 'content discoverability brief exposes GEO section' );
maa_adapter_smoke_assert( is_array( $discoverability_brief['exceptions'] ?? null ), 'content discoverability brief exposes exceptions' );
maa_adapter_smoke_assert( is_array( $discoverability_brief['special_cases'] ?? null ), 'content discoverability brief exposes special cases' );
maa_adapter_smoke_assert( is_array( $discoverability_brief['proposal_allowed_fields'] ?? null ), 'content discoverability brief exposes proposal allowed fields' );

foreach (
	array(
		'/npcink-openclaw-adapter/v1/wp-diagnostics-summary'      => 'npcink-abilities-toolkit/wp-diagnostics-summary',
		'/npcink-openclaw-adapter/v1/active-plugins-detail'       => 'npcink-abilities-toolkit/wp-ops-diagnostics-detail',
		'/npcink-openclaw-adapter/v1/plugin-conflict-diagnostics' => 'npcink-abilities-toolkit/wp-ops-diagnostics-detail',
		'/npcink-openclaw-adapter/v1/current-user-permissions'    => 'npcink-abilities-toolkit/wp-ops-diagnostics-detail',
		'/npcink-openclaw-adapter/v1/recent-error-log'            => 'npcink-abilities-toolkit/wp-ops-diagnostics-detail',
		'/npcink-openclaw-adapter/v1/recent-error-log-tail'       => 'npcink-abilities-toolkit/wp-ops-diagnostics-detail',
		'/npcink-openclaw-adapter/v1/database-info'               => 'npcink-abilities-toolkit/wp-ops-diagnostics-detail',
		'/npcink-openclaw-adapter/v1/cron-events-detail'          => 'npcink-abilities-toolkit/wp-ops-diagnostics-detail',
	) as $diagnostic_route => $expected_ability_id
) {
	$diagnostic_result = maa_adapter_smoke_rest_result( 'GET', $diagnostic_route );
	$diagnostic_error  = is_array( $diagnostic_result['data'] ) ? $diagnostic_result['data'] : array();
	$diagnostic_data   = is_array( $diagnostic_error['data'] ?? null ) ? $diagnostic_error['data'] : array();

	maa_adapter_smoke_assert( 404 === (int) $diagnostic_result['status'], 'adapter diagnostic shortcut route is removed: ' . $diagnostic_route );
	maa_adapter_smoke_assert( 'rest_no_route' === (string) ( $diagnostic_error['code'] ?? '' ), 'removed diagnostic shortcut returns rest_no_route: ' . $diagnostic_route );
}

$sensitive_read_ability_id = 'npcink-abilities-toolkit/wp-ops-diagnostics-detail';
$sensitive_read_input      = array(
	'include_error_log'     => true,
	'tail_lines'            => 5,
	'max_plugins_per_group' => 5,
);
$sensitive_read_request    = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/read-requests',
	array(
		'ability_id'              => $sensitive_read_ability_id,
		'input'                   => $sensitive_read_input,
		'requested_input_summary' => 'Adapter smoke bounded diagnostics read',
		'data_classes'            => array( 'diagnostics', 'logs' ),
		'redaction_level'         => 'strict',
		'purpose'                 => 'Adapter smoke verifies Core sensitive read grant; authorization header: SHOULD_NOT_LEAK',
		'caller'                  => array(
			'via'       => 'npcink-openclaw-adapter',
			'token'     => 'SHOULD_NOT_LEAK',
			'ability_id' => $sensitive_read_ability_id,
		),
		'bounds'                  => array(
			'max_rows'      => 10,
			'tail_lines'    => 5,
			'denied_fields' => array( 'authorization', 'cookie', 'application_password' ),
		),
	)
);
$sensitive_read_request_id = (string) ( $sensitive_read_request['request_id'] ?? '' );
$maa_adapter_smoke_cleanup_read_request_ids[] = $sensitive_read_request_id;
maa_adapter_smoke_assert( '' !== $sensitive_read_request_id, 'adapter creates Core sensitive read request' );
maa_adapter_smoke_assert( 'pending' === (string) ( $sensitive_read_request['status'] ?? '' ), 'adapter sensitive read request starts pending' );
maa_adapter_smoke_assert( false === strpos( (string) wp_json_encode( $sensitive_read_request ), 'SHOULD_NOT_LEAK' ), 'adapter sensitive read request response does not leak secret sentinel' );

$sensitive_read_list = maa_adapter_smoke_rest( 'GET', '/npcink-openclaw-adapter/v1/read-requests', array( 'status' => 'pending', 'limit' => 10 ) );
$sensitive_read_listed = false;
foreach ( (array) ( $sensitive_read_list['items'] ?? array() ) as $sensitive_read_item ) {
	if ( is_array( $sensitive_read_item ) && $sensitive_read_request_id === (string) ( $sensitive_read_item['request_id'] ?? '' ) ) {
		$sensitive_read_listed = true;
		break;
	}
}
maa_adapter_smoke_assert( $sensitive_read_listed, 'adapter lists pending Core sensitive read request' );

$sensitive_read_detail = maa_adapter_smoke_rest( 'GET', '/npcink-openclaw-adapter/v1/read-requests/' . rawurlencode( $sensitive_read_request_id ) );
maa_adapter_smoke_assert( $sensitive_read_request_id === (string) ( $sensitive_read_detail['request_id'] ?? '' ), 'adapter reads Core sensitive read request status' );
maa_adapter_smoke_assert( is_array( $sensitive_read_detail['audit_timeline'] ?? null ), 'adapter sensitive read detail includes Core audit timeline' );

$sensitive_read_approved = maa_adapter_smoke_rest(
	'POST',
	'/npcink-governance-core/v1/read-requests/' . rawurlencode( $sensitive_read_request_id ) . '/approve',
	array(
		'note'            => 'Adapter smoke approval',
		'redaction_level' => 'strict',
		'max_rows'        => 10,
		'tail_lines'      => 5,
		'denied_fields'   => array( 'authorization', 'cookie', 'application_password' ),
	)
);
maa_adapter_smoke_assert( 'approved' === (string) ( $sensitive_read_approved['status'] ?? '' ), 'Core approves adapter-created sensitive read request' );

$sensitive_read_wrong_input = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id'       => $sensitive_read_ability_id,
		'input'            => array_merge( $sensitive_read_input, array( 'tail_lines' => 6 ) ),
		'read_request_id'  => $sensitive_read_request_id,
	)
);
maa_adapter_smoke_assert( 409 === (int) $sensitive_read_wrong_input['status'], 'adapter rejects sensitive read when input no longer matches Core grant' );

$sensitive_read_granted = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id'      => $sensitive_read_ability_id,
		'input'           => $sensitive_read_input,
		'read_request_id' => $sensitive_read_request_id,
	)
);
$sensitive_read_context = is_array( $sensitive_read_granted['read_context']['npcink_governance_core'] ?? null ) ? $sensitive_read_granted['read_context']['npcink_governance_core'] : array();
maa_adapter_smoke_assert( true === (bool) ( $sensitive_read_granted['read_context']['read_authorization_granted'] ?? false ), 'adapter sensitive read carries granted flag' );
maa_adapter_smoke_assert( $sensitive_read_request_id === (string) ( $sensitive_read_context['read_request_id'] ?? '' ), 'adapter sensitive read context binds Core request id' );
maa_adapter_smoke_assert( 'npcink_governance_core' === (string) ( $sensitive_read_context['core_authorization_truth'] ?? '' ), 'adapter sensitive read context names Core as authorization truth' );
maa_adapter_smoke_assert( false === (bool) ( $sensitive_read_context['commit_execution'] ?? true ), 'adapter sensitive read context disables commit execution' );
maa_adapter_smoke_assert( false === (bool) ( $sensitive_read_context['write_execution'] ?? true ), 'adapter sensitive read context disables write execution' );
maa_adapter_smoke_assert( true === (bool) ( $sensitive_read_granted['redaction_applied'] ?? false ), 'adapter applies redaction for Core-authorized sensitive read' );
maa_adapter_smoke_assert( 5 === (int) ( $sensitive_read_granted['redaction_summary']['tail_lines'] ?? 0 ), 'adapter sensitive read redaction summary carries Core tail_lines bound' );
maa_adapter_smoke_assert( is_array( $sensitive_read_granted['result'] ?? null ), 'adapter returns sensitive read result after Core grant' );
maa_adapter_smoke_assert( false === strpos( (string) wp_json_encode( $sensitive_read_granted ), 'SHOULD_NOT_LEAK' ), 'adapter sensitive read response does not leak secret sentinel' );

$workflow_recipes = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id' => 'npcink-abilities-toolkit/list-workflow-recipes',
		'input'      => array(),
	)
);
maa_adapter_smoke_assert( isset( $workflow_recipes['result']['cases']['article_publish_preflight'] ), 'adapter returns workflow recipe list result' );
maa_adapter_smoke_assert( isset( $workflow_recipes['result']['cases']['article_media_handoff'] ), 'adapter returns article media handoff workflow recipe' );

$workflow_recipe = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id' => 'npcink-abilities-toolkit/get-workflow-recipe',
		'input'      => array(
			'recipe_id' => 'npcink-abilities-toolkit/recipes/comment-compliance-handoff',
		),
	)
);
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/get-workflow-recipe' === (string) ( $workflow_recipe['ability_id'] ?? '' ), 'adapter runs workflow recipe detail ability' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/get-comment-compliance-handoff' === (string) ( $workflow_recipe['result']['entrypoint_ability_id'] ?? '' ), 'adapter returns workflow recipe detail result' );

$article_media_workflow_recipe = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id' => 'npcink-abilities-toolkit/get-workflow-recipe',
		'input'      => array(
			'recipe_id' => 'npcink-abilities-toolkit/recipes/article-media-handoff',
		),
	)
);
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/build-media-seo-assets' === (string) ( $article_media_workflow_recipe['result']['entrypoint_ability_id'] ?? '' ), 'adapter returns article media handoff entrypoint ability' );
maa_adapter_smoke_assert( true === (bool) ( $article_media_workflow_recipe['result']['host_governed_write_boundary'] ?? false ), 'adapter returns article media handoff host-governed boundary' );

$site_info = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id'      => 'npcink-abilities-toolkit/site-info',
		'input'           => array(),
		'proposal_id'     => 'proposal-log-context-smoke',
		'correlation_id'  => 'correlation-log-context-smoke',
	)
);
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/site-info' === (string) ( $site_info['ability_id'] ?? '' ), 'adapter runs site-info through generic read ability route' );
maa_adapter_smoke_assert( is_array( $site_info['result'] ?? null ), 'site-info shortcut returns a result object' );
maa_adapter_smoke_assert( is_array( $site_info['log_context'] ?? null ), 'adapter read response exposes AI request log context' );
maa_adapter_smoke_assert( 'proposal-log-context-smoke' === (string) ( $site_info['log_context']['proposal_id'] ?? '' ), 'adapter read log context carries proposal id' );
maa_adapter_smoke_assert( 'correlation-log-context-smoke' === (string) ( $site_info['log_context']['correlation_id'] ?? '' ), 'adapter read log context carries correlation id' );
maa_adapter_smoke_assert( '/npcink-openclaw-adapter/v1/run-read-ability' === (string) ( $site_info['log_context']['adapter_route'] ?? '' ), 'adapter read log context carries adapter_route' );
maa_adapter_smoke_assert( 'npcink-governance-core' === (string) ( $site_info['log_context']['governance_source'] ?? '' ), 'adapter read log context carries governance_source' );

$media = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id' => 'npcink-abilities-toolkit/list-media',
		'input'      => array(
			'per_page' => 1,
		),
	)
);
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/list-media' === (string) ( $media['ability_id'] ?? '' ), 'adapter runs media read through generic read ability route' );
maa_adapter_smoke_assert( is_array( $media['result'] ?? null ), 'media shortcut returns a result object' );

$media_metadata_optimization = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id' => 'npcink-abilities-toolkit/optimize-media-metadata',
		'input'      => array(
			'article_title' => 'Adapter media metadata smoke',
			'focus_keyword' => 'adapter metadata',
			'media_assets'  => array(
				array(
					'attachment_id' => 0,
					'title'         => '',
					'alt'           => '',
					'caption'       => '',
					'description'   => '',
					'mime_type'     => 'image/jpeg',
					'file_name'     => 'adapter-metadata-smoke.jpg',
				),
			),
		),
	)
);
$media_metadata_data = is_array( $media_metadata_optimization['result']['data'] ?? null ) ? $media_metadata_optimization['result']['data'] : array();
$media_metadata_asset = is_array( $media_metadata_data['assets'][0] ?? null ) ? $media_metadata_data['assets'][0] : array();
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/optimize-media-metadata' === (string) ( $media_metadata_optimization['ability_id'] ?? '' ), 'adapter runs media metadata optimization through generic read ability route' );
maa_adapter_smoke_assert( 1 === (int) ( $media_metadata_data['summary']['asset_count'] ?? 0 ), 'media metadata optimization returns one asset suggestion' );
maa_adapter_smoke_assert( is_array( $media_metadata_asset['suggestions'] ?? null ), 'media metadata optimization returns metadata suggestions' );
maa_adapter_smoke_assert( false === in_array( 'npcink-abilities-toolkit/optimize-media-metadata', (array) ( $health['supported_execute_ability_ids'] ?? array() ), true ), 'media metadata optimization stays out of Adapter final write supported profiles' );

$pages = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id' => 'npcink-abilities-toolkit/list-pages',
		'input'      => array(
			'per_page' => 1,
		),
	)
);
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/list-pages' === (string) ( $pages['ability_id'] ?? '' ), 'adapter runs pages read through generic read ability route' );
maa_adapter_smoke_assert( is_array( $pages['result'] ?? null ), 'pages shortcut returns a result object' );

$write_run = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id' => 'npcink-abilities-toolkit/create-draft',
		'input'      => array(
			'title'   => 'Adapter smoke should not execute writes',
			'dry_run' => true,
			'commit'  => false,
		),
	)
);
maa_adapter_smoke_assert( 403 === (int) $write_run['status'], 'adapter refuses direct execution for proposal-required ability' );

$destructive_run = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/run-read-ability',
	array(
		'ability_id' => 'npcink-abilities-toolkit/delete-media-permanently',
		'input'      => array(
			'attachment_id' => 0,
			'dry_run'       => true,
			'commit'        => false,
		),
	)
);
maa_adapter_smoke_assert( 403 === (int) $destructive_run['status'], 'adapter refuses direct execution for destructive ability' );

$trash_post_id = maa_adapter_smoke_create_trash_post_fixture();
$maa_adapter_smoke_cleanup_post_ids[] = $trash_post_id;
$trash_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/trash-post',
		'title'      => 'Adapter approved trash execution smoke',
		'summary'    => 'Adapter executes one approved trash-post proposal after Core preflight.',
		'input'      => array(
			'post_id' => $trash_post_id,
			'dry_run' => true,
			'commit'  => false,
		),
		'preview'    => array(
			'action'           => 'trash_post',
			'post_id'          => $trash_post_id,
			'dry_run'          => true,
			'commit_execution' => false,
		),
		'caller'     => array(
			'external_thread_id' => 'adapter-approved-execution-smoke',
		),
	)
);
$trash_proposal_id = (string) ( $trash_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $trash_proposal_id;
maa_adapter_smoke_assert( '' !== $trash_proposal_id, 'adapter creates trash-post proposal for approved execution smoke' );
maa_adapter_smoke_assert( 'pending' === (string) ( $trash_proposal['status'] ?? '' ), 'adapter trash-post proposal starts pending' );
$proposal_create_event = maa_adapter_smoke_observability_event( 'adapter.proposal.create', 'ok', '/npcink-openclaw-adapter/v1/proposals' );
maa_adapter_smoke_assert( ! empty( $proposal_create_event ), 'adapter emits proposal create success observability event' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/trash-post' === (string) ( $proposal_create_event['ability_id'] ?? '' ), 'adapter proposal create success event carries ability id' );
maa_adapter_smoke_assert_observability_safe( $proposal_create_event, 'adapter proposal create success event' );

$pending_execute = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/execute-approved-proposal',
	array(
		'proposal_id' => $trash_proposal_id,
	)
);
maa_adapter_smoke_assert( 409 === (int) $pending_execute['status'], 'adapter execute refuses pending proposal' );
maa_adapter_smoke_assert( 'publish' === (string) get_post_status( $trash_post_id ), 'adapter pending execute leaves post published' );

$approved_trash = maa_adapter_smoke_rest(
	'POST',
	'/npcink-governance-core/v1/proposals/' . rawurlencode( $trash_proposal_id ) . '/approve',
	array(
		'note' => 'Approve adapter trash execution smoke.',
	)
);
maa_adapter_smoke_assert( 'approved' === (string) ( $approved_trash['status'] ?? '' ), 'Core admin REST approval succeeds for adapter trash execution smoke' );

$executed_trash = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $trash_proposal_id ) . '/execute' );
maa_adapter_smoke_assert( 'executed' === (string) ( $executed_trash['status'] ?? '' ), 'adapter executes approved trash-post proposal' );
maa_adapter_smoke_assert( $trash_proposal_id === (string) ( $executed_trash['proposal_id'] ?? '' ), 'adapter execute response carries proposal id' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/trash-post' === (string) ( $executed_trash['ability_id'] ?? '' ), 'adapter execute response carries ability id' );
maa_adapter_smoke_assert( '' !== (string) ( $executed_trash['correlation_id'] ?? '' ), 'adapter execute response carries correlation id' );
maa_adapter_smoke_assert( '' !== (string) ( $executed_trash['adapter_request_id'] ?? '' ), 'adapter execute response carries adapter request id' );
maa_adapter_smoke_assert( true === (bool) ( $executed_trash['approval_context']['approval_commit_authorized'] ?? false ), 'adapter execute response carries approval context' );
maa_adapter_smoke_assert( false === (bool) ( $executed_trash['commit_execution'] ?? true ), 'adapter execute response preserves commit_execution=false' );
maa_adapter_smoke_assert( true === (bool) ( $executed_trash['result']['trashed'] ?? false ), 'adapter execute trashes post' );
maa_adapter_smoke_assert( false === (bool) ( $executed_trash['result']['dry_run'] ?? true ), 'adapter execute returns non-dry-run ability result' );
maa_adapter_smoke_assert( 'trash' === (string) get_post_status( $trash_post_id ), 'adapter approved execution moves post to trash' );
$duplicate_execute = maa_adapter_smoke_rest_result( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $trash_proposal_id ) . '/execute' );
maa_adapter_smoke_assert( 409 === (int) $duplicate_execute['status'], 'adapter rejects duplicate approved proposal execution' );
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_execution_already_completed' === (string) ( $duplicate_execute['data']['code'] ?? '' ), 'adapter duplicate execute uses completed execution error code' );
maa_adapter_smoke_assert( $trash_proposal_id === (string) ( $duplicate_execute['data']['data']['execution_record']['proposal_id'] ?? '' ), 'adapter duplicate execute returns stored execution record' );
maa_adapter_smoke_assert( (string) ( $executed_trash['execution_record']['adapter_request_id'] ?? '' ) === (string) ( $duplicate_execute['data']['data']['execution_record']['adapter_request_id'] ?? '' ), 'adapter duplicate execute preserves original adapter request id' );

$cached_preflight_post_id = maa_adapter_smoke_create_trash_post_fixture();
$maa_adapter_smoke_cleanup_post_ids[] = $cached_preflight_post_id;
$cached_preflight_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/trash-post',
		'title'      => 'Adapter cached preflight handoff smoke',
		'summary'    => 'Adapter executes one approved trash-post proposal after Adapter commit-preflight caches the Core handoff.',
		'input'      => array(
			'post_id' => $cached_preflight_post_id,
			'dry_run' => true,
			'commit'  => false,
		),
		'preview'    => array(
			'action'           => 'trash_post',
			'post_id'          => $cached_preflight_post_id,
			'dry_run'          => true,
			'commit_execution' => false,
		),
		'caller'     => array(
			'external_thread_id' => 'adapter-cached-preflight-handoff-smoke',
		),
	)
);
$cached_preflight_proposal_id = (string) ( $cached_preflight_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $cached_preflight_proposal_id;
maa_adapter_smoke_rest(
	'POST',
	'/npcink-governance-core/v1/proposals/' . rawurlencode( $cached_preflight_proposal_id ) . '/approve',
	array(
		'note' => 'Approve Adapter cached preflight handoff smoke.',
	)
);
$cached_preflight = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $cached_preflight_proposal_id ) . '/commit-preflight' );
maa_adapter_smoke_assert( true === (bool) ( $cached_preflight['adapter_preflight_handoff_cached'] ?? false ), 'adapter commit-preflight caches execution handoff' );
maa_adapter_smoke_assert( false === (bool) ( $cached_preflight['commit_execution'] ?? true ), 'adapter cached preflight keeps commit_execution=false' );
$cached_preflight_execute = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $cached_preflight_proposal_id ) . '/execute' );
maa_adapter_smoke_assert( 'executed' === (string) ( $cached_preflight_execute['status'] ?? '' ), 'adapter cached preflight handoff execute succeeds' );
maa_adapter_smoke_assert( 'adapter_cached_handoff' === (string) ( $cached_preflight_execute['preflight_source'] ?? '' ), 'adapter execute consumes cached preflight handoff' );
maa_adapter_smoke_assert( 'trash' === (string) get_post_status( $cached_preflight_post_id ), 'adapter cached preflight handoff execution moves post to trash' );

$failed_execution_post_id = maa_adapter_smoke_create_trash_post_fixture();
$maa_adapter_smoke_cleanup_post_ids[] = $failed_execution_post_id;
$failed_execution_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/trash-post',
		'title'      => 'Adapter failed execution record smoke',
		'summary'    => 'Adapter records a bounded failed execution summary after Core preflight is consumed.',
		'input'      => array(
			'post_id' => $failed_execution_post_id,
			'dry_run' => true,
			'commit'  => false,
		),
		'preview'    => array(
			'action'           => 'trash_post',
			'post_id'          => $failed_execution_post_id,
			'dry_run'          => true,
			'commit_execution' => false,
		),
		'caller'     => array(
			'external_thread_id' => 'adapter-failed-execution-record-smoke',
		),
	)
);
$failed_execution_proposal_id = (string) ( $failed_execution_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $failed_execution_proposal_id;
maa_adapter_smoke_rest(
	'POST',
	'/npcink-governance-core/v1/proposals/' . rawurlencode( $failed_execution_proposal_id ) . '/approve',
	array(
		'note' => 'Approve Adapter failed execution record smoke.',
	)
);
$failed_execution_preflight = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $failed_execution_proposal_id ) . '/commit-preflight' );
maa_adapter_smoke_assert( true === (bool) ( $failed_execution_preflight['adapter_preflight_handoff_cached'] ?? false ), 'adapter failed execution smoke caches preflight handoff' );
maa_adapter_smoke_assert( false === (bool) ( $failed_execution_preflight['commit_execution'] ?? true ), 'adapter failed execution preflight keeps commit_execution=false' );
wp_delete_post( $failed_execution_post_id, true );
maa_adapter_smoke_assert( false === get_post_status( $failed_execution_post_id ), 'adapter failed execution smoke removes target post before execute' );
$failed_execution = maa_adapter_smoke_rest_result( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $failed_execution_proposal_id ) . '/execute' );
maa_adapter_smoke_assert( $failed_execution['status'] >= 400, 'adapter failed execution returns an error response' );
maa_adapter_smoke_assert( 'failed' === (string) ( $failed_execution['data']['data']['execution_record']['status'] ?? '' ), 'adapter failed execution returns failed execution record' );
maa_adapter_smoke_assert( $failed_execution_proposal_id === (string) ( $failed_execution['data']['data']['execution_record']['proposal_id'] ?? '' ), 'adapter failed execution record carries proposal id' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/trash-post' === (string) ( $failed_execution['data']['data']['execution_record']['ability_id'] ?? '' ), 'adapter failed execution record carries ability id' );
maa_adapter_smoke_assert( '' !== (string) ( $failed_execution['data']['data']['execution_record']['correlation_id'] ?? '' ), 'adapter failed execution record carries correlation id' );
maa_adapter_smoke_assert( 0 === (int) ( $failed_execution['data']['data']['execution_record']['executed_count'] ?? -1 ), 'adapter failed execution record carries executed count' );
maa_adapter_smoke_assert( 1 === (int) ( $failed_execution['data']['data']['execution_record']['failed_count'] ?? 0 ), 'adapter failed execution record carries failed count' );
maa_adapter_smoke_assert( 'npcink_abilities_toolkit_post_not_found' === (string) ( $failed_execution['data']['data']['execution_record']['error_code'] ?? '' ), 'adapter failed execution record carries ability error code' );
maa_adapter_smoke_assert( false === (bool) ( $failed_execution['data']['data']['execution_record']['commit_execution'] ?? true ), 'adapter failed execution record keeps commit_execution=false' );

$approve_execute_post_id = maa_adapter_smoke_create_trash_post_fixture();
$maa_adapter_smoke_cleanup_post_ids[] = $approve_execute_post_id;
$approve_execute_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/trash-post',
		'title'      => 'Adapter unified approve execute smoke',
		'summary'    => 'Adapter approves through Core and executes one trash-post proposal.',
		'input'      => array(
			'post_id' => $approve_execute_post_id,
			'dry_run' => true,
			'commit'  => false,
		),
		'preview'    => array(
			'action'           => 'trash_post',
			'post_id'          => $approve_execute_post_id,
			'dry_run'          => true,
			'commit_execution' => false,
		),
		'caller'     => array(
			'external_thread_id' => 'adapter-unified-approve-execute-smoke',
		),
	)
);
$approve_execute_proposal_id = (string) ( $approve_execute_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $approve_execute_proposal_id;
maa_adapter_smoke_assert( '' !== $approve_execute_proposal_id, 'adapter creates trash-post proposal for approve-and-execute smoke' );
$approve_execute_result = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $approve_execute_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( true === (bool) ( $approve_execute_result['success'] ?? false ), 'adapter approve-and-execute succeeds for pending trash-post proposal' );
maa_adapter_smoke_assert( $approve_execute_proposal_id === (string) ( $approve_execute_result['proposal_id'] ?? '' ), 'adapter approve-and-execute response carries proposal id' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/trash-post' === (string) ( $approve_execute_result['ability_id'] ?? '' ), 'adapter approve-and-execute response carries ability id' );
maa_adapter_smoke_assert( $approve_execute_post_id === (int) ( $approve_execute_result['post_id'] ?? 0 ), 'adapter approve-and-execute response carries post id' );
maa_adapter_smoke_assert( 'pending' === (string) ( $approve_execute_result['status_before'] ?? '' ), 'adapter approve-and-execute records pending status before approval' );
maa_adapter_smoke_assert( true === (bool) ( $approve_execute_result['approved_by_adapter'] ?? false ), 'adapter approve-and-execute auto approves pending proposal through Core' );
maa_adapter_smoke_assert( '' !== (string) ( $approve_execute_result['correlation_id'] ?? '' ), 'adapter approve-and-execute response carries correlation id' );
maa_adapter_smoke_assert( false === (bool) ( $approve_execute_result['core_commit_execution'] ?? true ), 'adapter approve-and-execute preserves Core commit_execution=false' );
maa_adapter_smoke_assert( 'publish' === (string) ( $approve_execute_result['execution']['post_status_before'] ?? '' ), 'adapter approve-and-execute records post status before execution' );
maa_adapter_smoke_assert( 'trash' === (string) ( $approve_execute_result['execution']['post_status_after'] ?? '' ), 'adapter approve-and-execute records post status after execution' );
maa_adapter_smoke_assert( true === (bool) ( $approve_execute_result['execution']['success'] ?? false ), 'adapter approve-and-execute execution result succeeds' );
maa_adapter_smoke_assert( 'trash' === (string) get_post_status( $approve_execute_post_id ), 'adapter approve-and-execute moves pending proposal post to trash' );
$duplicate_approve_execute = maa_adapter_smoke_rest_result( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $approve_execute_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( 409 === (int) $duplicate_approve_execute['status'], 'adapter rejects duplicate approve-and-execute request' );
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_execution_already_completed' === (string) ( $duplicate_approve_execute['data']['code'] ?? '' ), 'adapter duplicate approve-and-execute uses completed execution error code' );
maa_adapter_smoke_assert( $approve_execute_proposal_id === (string) ( $duplicate_approve_execute['data']['data']['execution_record']['proposal_id'] ?? '' ), 'adapter duplicate approve-and-execute returns stored execution record' );
maa_adapter_smoke_assert( (string) ( $approve_execute_result['execution_record']['adapter_request_id'] ?? '' ) === (string) ( $duplicate_approve_execute['data']['data']['execution_record']['adapter_request_id'] ?? '' ), 'adapter duplicate approve-and-execute preserves original adapter request id' );

$batch_post_id = maa_adapter_smoke_create_trash_post_fixture();
$batch_second_post_id = maa_adapter_smoke_create_trash_post_fixture();
$maa_adapter_smoke_cleanup_post_ids[] = $batch_post_id;
$maa_adapter_smoke_cleanup_post_ids[] = $batch_second_post_id;
$batch_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan',
		'title'      => 'Adapter batch approve execute smoke',
		'summary'    => 'Adapter approves through Core and executes a bounded write_actions trash-post batch.',
		'input'      => array(
			'write_actions' => array(
				array(
					'action_id'         => 'trash-post-' . $batch_post_id,
					'target_ability_id' => 'npcink-abilities-toolkit/trash-post',
					'input'             => array(
						'post_id' => $batch_post_id,
						'dry_run' => true,
						'commit'  => false,
					),
					'requires_approval' => true,
					'commit_execution'  => false,
					'proposal_ready'    => true,
				),
				array(
					'action_id'         => 'trash-post-' . $batch_second_post_id,
					'target_ability_id' => 'npcink-abilities-toolkit/trash-post',
					'input'             => array(
						'post_id' => $batch_second_post_id,
						'dry_run' => true,
						'commit'  => false,
					),
					'requires_approval' => true,
					'commit_execution'  => false,
					'proposal_ready'    => true,
				),
			),
		),
		'preview'    => array(
			'action'           => 'batch_trash_posts',
			'action_count'     => 2,
			'proposal_ready'   => true,
			'commit_execution' => false,
		),
		'caller'     => array(
			'external_thread_id' => 'adapter-batch-approve-execute-smoke',
		),
	)
);
$batch_proposal_id = (string) ( $batch_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $batch_proposal_id;
maa_adapter_smoke_assert( '' !== $batch_proposal_id, 'adapter creates write_actions batch proposal for approve-and-execute smoke' );
$batch_result = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $batch_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( true === (bool) ( $batch_result['success'] ?? false ), 'adapter batch approve-and-execute succeeds for supported write_actions' );
maa_adapter_smoke_assert( 'batch_write_actions' === (string) ( $batch_result['execution_mode'] ?? '' ), 'adapter batch approve-and-execute reports batch execution mode' );
maa_adapter_smoke_assert( 2 === (int) ( $batch_result['executed_count'] ?? 0 ), 'adapter batch approve-and-execute reports executed count' );
maa_adapter_smoke_assert( 0 === (int) ( $batch_result['failed_count'] ?? 1 ), 'adapter batch approve-and-execute reports zero failures' );
maa_adapter_smoke_assert( is_array( $batch_result['results'] ?? null ) && 2 === count( $batch_result['results'] ), 'adapter batch approve-and-execute returns per-action results' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/trash-post' === (string) ( $batch_result['results'][0]['target_ability_id'] ?? '' ), 'adapter batch approve-and-execute result carries target ability id' );
maa_adapter_smoke_assert( 'trash' === (string) get_post_status( $batch_post_id ), 'adapter batch approve-and-execute trashes first post' );
maa_adapter_smoke_assert( 'trash' === (string) get_post_status( $batch_second_post_id ), 'adapter batch approve-and-execute trashes second post' );

$referenced_batch_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan',
		'title'      => 'Adapter output reference batch smoke',
		'summary'    => 'Adapter resolves prior action outputs inside one approved write_actions batch.',
		'input'      => array(
			'write_actions' => array(
				array(
					'action_id'         => 'create-draft',
					'target_ability_id' => 'npcink-abilities-toolkit/create-draft',
					'input'             => array(
						'title'          => 'Adapter referenced batch draft',
						'content'        => 'Adapter output reference batch smoke.',
						'content_format' => 'plain',
						'dry_run'        => true,
						'commit'         => false,
					),
					'requires_approval' => true,
					'commit_execution'  => false,
					'proposal_ready'    => true,
				),
				array(
					'action_id'         => 'update-created-draft',
					'target_ability_id' => 'npcink-abilities-toolkit/update-post',
					'input'             => array(
						'post_id'        => '$outputs.create-draft.post_id',
						'title'          => 'Adapter referenced batch updated draft',
						'content'        => 'Adapter resolved create-draft output before update.',
						'content_format' => 'plain',
						'dry_run'        => true,
						'commit'         => false,
					),
					'requires_approval' => true,
					'commit_execution'  => false,
					'proposal_ready'    => true,
				),
				array(
					'action_id'         => 'trash-created-draft',
					'target_ability_id' => 'npcink-abilities-toolkit/trash-post',
					'input'             => array(
						'post_id' => '$outputs.create-draft.post_id',
						'dry_run' => true,
						'commit'  => false,
					),
					'requires_approval' => true,
					'commit_execution'  => false,
					'proposal_ready'    => true,
				),
			),
		),
		'preview'    => array(
			'action'           => 'batch_referenced_draft_lifecycle',
			'action_count'     => 3,
			'proposal_ready'   => true,
			'commit_execution' => false,
		),
		'caller'     => array(
			'external_thread_id' => 'adapter-output-reference-batch-smoke',
		),
	)
);
$referenced_batch_proposal_id = (string) ( $referenced_batch_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $referenced_batch_proposal_id;
maa_adapter_smoke_assert( '' !== $referenced_batch_proposal_id, 'adapter creates output-reference write_actions batch proposal' );
$referenced_batch_result = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $referenced_batch_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( true === (bool) ( $referenced_batch_result['success'] ?? false ), 'adapter batch approve-and-execute succeeds with output references' );
maa_adapter_smoke_assert( 3 === (int) ( $referenced_batch_result['executed_count'] ?? 0 ), 'adapter output-reference batch executes all actions' );
$referenced_batch_post_id = (int) ( $referenced_batch_result['results'][0]['post_id'] ?? 0 );
$maa_adapter_smoke_cleanup_post_ids[] = $referenced_batch_post_id;
maa_adapter_smoke_assert( $referenced_batch_post_id > 0, 'adapter output-reference batch creates a draft post' );
maa_adapter_smoke_assert( $referenced_batch_post_id === (int) ( $referenced_batch_result['results'][1]['post_id'] ?? 0 ), 'adapter output-reference batch updates the created draft' );
maa_adapter_smoke_assert( $referenced_batch_post_id === (int) ( $referenced_batch_result['results'][2]['post_id'] ?? 0 ), 'adapter output-reference batch trashes the created draft' );
maa_adapter_smoke_assert( 'trash' === (string) get_post_status( $referenced_batch_post_id ), 'adapter output-reference batch leaves created draft trashed' );

$embedded_reference_batch_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan',
		'title'      => 'Adapter embedded output reference batch smoke',
		'summary'    => 'Adapter must reject embedded output reference tokens before batch execution.',
		'input'      => array(
			'write_actions' => array(
				array(
					'action_id'         => 'embedded-output-reference',
					'target_ability_id' => 'npcink-abilities-toolkit/create-draft',
					'input'             => array(
						'title'   => 'Adapter embedded output reference batch',
						'content' => 'prefix-$outputs.embedded-output-reference.post_id',
						'dry_run' => true,
						'commit'  => false,
					),
					'requires_approval' => true,
					'commit_execution'  => false,
					'proposal_ready'    => true,
				),
			),
		),
		'preview'    => array(
			'action'           => 'batch_embedded_output_reference',
			'proposal_ready'   => true,
			'commit_execution' => false,
		),
	)
);
$embedded_reference_batch_proposal_id = (string) ( $embedded_reference_batch_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $embedded_reference_batch_proposal_id;
$embedded_reference_batch_result = maa_adapter_smoke_rest_result( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $embedded_reference_batch_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( 400 === (int) $embedded_reference_batch_result['status'], 'adapter batch approve-and-execute rejects embedded output reference tokens before execution' );
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_output_reference_invalid' === (string) ( $embedded_reference_batch_result['data']['code'] ?? '' ), 'adapter embedded output reference execution rejection uses output reference invalid code' );

$bad_batch_post_id = maa_adapter_smoke_create_trash_post_fixture();
$bad_batch_second_post_id = maa_adapter_smoke_create_trash_post_fixture();
$maa_adapter_smoke_cleanup_post_ids[] = $bad_batch_post_id;
$maa_adapter_smoke_cleanup_post_ids[] = $bad_batch_second_post_id;
$bad_batch_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan',
		'title'      => 'Adapter bad batch approve execute smoke',
		'summary'    => 'Adapter must fail closed when write_actions contains a non-supported target.',
		'input'      => array(
			'write_actions' => array(
				array(
					'action_id'         => 'trash-post-' . $bad_batch_post_id,
					'target_ability_id' => 'npcink-abilities-toolkit/trash-post',
					'input'             => array(
						'post_id' => $bad_batch_post_id,
						'dry_run' => true,
						'commit'  => false,
					),
					'requires_approval' => true,
					'commit_execution'  => false,
					'proposal_ready'    => true,
				),
					array(
						'action_id'         => 'set-author-' . $bad_batch_second_post_id,
						'target_ability_id' => 'npcink-abilities-toolkit/set-post-author',
						'input'             => array(
							'post_id'   => $bad_batch_second_post_id,
							'author_id' => 1,
							'dry_run'   => true,
							'commit'    => false,
						),
					'requires_approval' => true,
					'commit_execution'  => false,
					'proposal_ready'    => true,
				),
			),
		),
		'preview'    => array(
			'action'           => 'bad_batch_trash_posts',
			'action_count'     => 2,
			'proposal_ready'   => true,
			'commit_execution' => false,
		),
	)
);
$bad_batch_proposal_id = (string) ( $bad_batch_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $bad_batch_proposal_id;
$bad_batch_result = maa_adapter_smoke_rest_result( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $bad_batch_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( 403 === (int) $bad_batch_result['status'], 'adapter batch approve-and-execute rejects non-supported write_action' );
maa_adapter_smoke_assert( 'publish' === (string) get_post_status( $bad_batch_post_id ), 'adapter bad batch does not execute allowed action before failing closed' );
maa_adapter_smoke_assert( 'publish' === (string) get_post_status( $bad_batch_second_post_id ), 'adapter bad batch does not execute non-supported action' );

$core_proxy_batch_post_id = maa_adapter_smoke_create_trash_post_fixture();
$maa_adapter_smoke_cleanup_post_ids[] = $core_proxy_batch_post_id;
$core_proxy_batch_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan',
		'title'      => 'Adapter core proxy batch reject smoke',
		'summary'    => 'Adapter must fail closed when write_actions requests Core proxy execution.',
		'input'      => array(
			'write_actions' => array(
				array(
					'action_id'          => 'core-proxy-trash-post',
					'target_ability_id'  => 'npcink-abilities-toolkit/trash-post',
					'input'              => array(
						'post_id' => $core_proxy_batch_post_id,
						'dry_run' => true,
						'commit'  => false,
					),
					'requires_approval'  => true,
					'core_proxy_execute' => true,
					'commit_execution'   => false,
					'proposal_ready'     => true,
				),
			),
		),
		'preview'    => array(
			'action'           => 'core_proxy_batch_reject',
			'proposal_ready'   => true,
			'commit_execution' => false,
		),
	)
);
$core_proxy_batch_proposal_id = (string) ( $core_proxy_batch_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $core_proxy_batch_proposal_id;
$core_proxy_batch_result = maa_adapter_smoke_rest_result( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $core_proxy_batch_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( 409 === (int) $core_proxy_batch_result['status'], 'adapter batch approve-and-execute rejects core_proxy_execute write_action' );
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_write_action_core_proxy_execute_unsupported' === (string) ( $core_proxy_batch_result['data']['code'] ?? '' ), 'adapter core_proxy_execute rejection uses stable error code' );
maa_adapter_smoke_assert( 'publish' === (string) get_post_status( $core_proxy_batch_post_id ), 'adapter core_proxy_execute batch does not execute allowed action' );

$commit_execution_batch_post_id = maa_adapter_smoke_create_trash_post_fixture();
$maa_adapter_smoke_cleanup_post_ids[] = $commit_execution_batch_post_id;
$commit_execution_batch_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan',
		'title'      => 'Adapter commit execution batch reject smoke',
		'summary'    => 'Adapter must fail closed when write_actions requests commit execution before Adapter execution.',
		'input'      => array(
			'write_actions' => array(
				array(
					'action_id'         => 'commit-execution-trash-post',
					'target_ability_id' => 'npcink-abilities-toolkit/trash-post',
					'input'             => array(
						'post_id' => $commit_execution_batch_post_id,
						'dry_run' => true,
						'commit'  => false,
					),
					'requires_approval' => true,
					'commit_execution'  => true,
					'proposal_ready'    => true,
				),
			),
		),
		'preview'    => array(
			'action'           => 'commit_execution_batch_reject',
			'proposal_ready'   => true,
			'commit_execution' => false,
		),
	)
);
$commit_execution_batch_proposal_id = (string) ( $commit_execution_batch_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $commit_execution_batch_proposal_id;
$commit_execution_batch_result = maa_adapter_smoke_rest_result( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $commit_execution_batch_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( 409 === (int) $commit_execution_batch_result['status'], 'adapter batch approve-and-execute rejects commit_execution write_action' );
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_write_action_commit_execution_unsupported' === (string) ( $commit_execution_batch_result['data']['code'] ?? '' ), 'adapter commit_execution rejection uses stable error code' );
maa_adapter_smoke_assert( 'publish' === (string) get_post_status( $commit_execution_batch_post_id ), 'adapter commit_execution batch does not execute allowed action' );

$approved_skip_post_id = maa_adapter_smoke_create_trash_post_fixture();
$maa_adapter_smoke_cleanup_post_ids[] = $approved_skip_post_id;
$approved_skip_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/trash-post',
		'title'      => 'Adapter approved skip smoke',
		'summary'    => 'Adapter skips Core approve for already approved trash-post proposal.',
		'input'      => array(
			'post_id' => $approved_skip_post_id,
			'dry_run' => true,
			'commit'  => false,
		),
		'preview'    => array(
			'action'           => 'trash_post',
			'post_id'          => $approved_skip_post_id,
			'dry_run'          => true,
			'commit_execution' => false,
		),
	)
);
$approved_skip_proposal_id = (string) ( $approved_skip_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $approved_skip_proposal_id;
maa_adapter_smoke_rest(
	'POST',
	'/npcink-governance-core/v1/proposals/' . rawurlencode( $approved_skip_proposal_id ) . '/approve',
	array(
		'note' => 'Approve before Adapter approve-and-execute smoke.',
	)
);
$approved_skip_result = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $approved_skip_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( true === (bool) ( $approved_skip_result['success'] ?? false ), 'adapter approve-and-execute succeeds for already approved proposal' );
maa_adapter_smoke_assert( 'approved' === (string) ( $approved_skip_result['status_before'] ?? '' ), 'adapter approve-and-execute records approved status before execution' );
maa_adapter_smoke_assert( false === (bool) ( $approved_skip_result['approved_by_adapter'] ?? true ), 'adapter approve-and-execute skips approve for already approved proposal' );
maa_adapter_smoke_assert( 'trash' === (string) get_post_status( $approved_skip_post_id ), 'adapter approve-and-execute moves already approved post to trash' );

$rejected_post_id = maa_adapter_smoke_create_trash_post_fixture();
$maa_adapter_smoke_cleanup_post_ids[] = $rejected_post_id;
$rejected_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/trash-post',
		'title'      => 'Adapter rejected approve execute smoke',
		'summary'    => 'Adapter must not execute rejected trash-post proposal.',
		'input'      => array(
			'post_id' => $rejected_post_id,
			'dry_run' => true,
			'commit'  => false,
		),
		'preview'    => array(
			'action'  => 'trash_post',
			'post_id' => $rejected_post_id,
		),
	)
);
$rejected_proposal_id = (string) ( $rejected_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $rejected_proposal_id;
maa_adapter_smoke_rest(
	'POST',
	'/npcink-governance-core/v1/proposals/' . rawurlencode( $rejected_proposal_id ) . '/reject',
	array(
		'note' => 'Reject Adapter approve-and-execute smoke.',
	)
);
$rejected_execute = maa_adapter_smoke_rest_result( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $rejected_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( 409 === (int) $rejected_execute['status'], 'adapter approve-and-execute rejects rejected proposal' );
maa_adapter_smoke_assert( 'proposal_rejected' === (string) ( $rejected_execute['data']['data']['operator_feedback']['status'] ?? '' ), 'adapter rejected proposal response returns operator feedback' );
maa_adapter_smoke_assert( 'Reject Adapter approve-and-execute smoke.' === (string) ( $rejected_execute['data']['data']['operator_feedback']['reasons'][0] ?? '' ), 'adapter rejected proposal feedback preserves Core rejection note' );
maa_adapter_smoke_assert( 'publish' === (string) get_post_status( $rejected_post_id ), 'adapter approve-and-execute does not execute rejected proposal' );

$blocked_post_id = maa_adapter_smoke_create_trash_post_fixture();
$maa_adapter_smoke_cleanup_post_ids[] = $blocked_post_id;
$blocked_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/trash-post',
		'title'      => 'Adapter blocked preflight smoke',
		'summary'    => 'Adapter must not execute if Core commit-preflight blocks the item.',
		'input'      => array(
			'post_id' => $blocked_post_id,
			'dry_run' => true,
			'commit'  => false,
		),
		'preview'    => array(
			'action'             => 'trash_post',
			'post_id'            => $blocked_post_id,
			'proposal_ready'     => false,
			'preflight_blockers' => array(
				array(
					'code'   => 'adapter_smoke_blocker',
					'reason' => 'Adapter smoke requires Core preflight failure.',
				),
			),
		),
	)
);
$blocked_proposal_id = (string) ( $blocked_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $blocked_proposal_id;
$blocked_execute = maa_adapter_smoke_rest_result( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $blocked_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( 409 === (int) $blocked_execute['status'], 'adapter approve-and-execute returns preflight failure' );
maa_adapter_smoke_assert( 'preflight_blocked' === (string) ( $blocked_execute['data']['data']['operator_feedback']['status'] ?? '' ), 'adapter preflight-blocked response returns operator feedback' );
maa_adapter_smoke_assert( false === (bool) ( $blocked_execute['data']['data']['operator_feedback']['core_evidence']['commit_execution'] ?? true ), 'adapter preflight-blocked feedback preserves no Core execution' );
maa_adapter_smoke_assert( 'publish' === (string) get_post_status( $blocked_post_id ), 'adapter approve-and-execute does not execute preflight-blocked proposal' );

$draft_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/create-draft',
		'title'      => 'Adapter draft approve execute smoke',
		'summary'    => 'Adapter approves through Core and executes one create-draft proposal.',
		'input'      => array(
			'title'          => 'Adapter approved draft smoke',
			'content'        => 'Adapter approved draft execution smoke.',
			'content_format' => 'plain',
			'dry_run'        => true,
			'commit'         => false,
		),
		'preview'    => array(
			'action'           => 'create_draft',
			'dry_run'          => true,
			'commit_execution' => false,
		),
		'caller'     => array(
			'external_thread_id' => 'adapter-create-draft-approve-execute-smoke',
		),
	)
);
$draft_proposal_id = (string) ( $draft_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $draft_proposal_id;
maa_adapter_smoke_assert( '' !== $draft_proposal_id, 'adapter creates create-draft proposal for approve-and-execute smoke' );
$draft_execute = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $draft_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( true === (bool) ( $draft_execute['success'] ?? false ), 'adapter approve-and-execute succeeds for pending create-draft proposal' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/create-draft' === (string) ( $draft_execute['ability_id'] ?? '' ), 'adapter approve-and-execute response carries create-draft ability id' );
maa_adapter_smoke_assert( (int) ( $draft_execute['post_id'] ?? 0 ) > 0, 'adapter approve-and-execute returns created draft post id' );
maa_adapter_smoke_assert( 'draft' === (string) ( $draft_execute['execution']['post_status_after'] ?? '' ), 'adapter approve-and-execute records draft status after creation' );
maa_adapter_smoke_assert( false === (bool) ( $draft_execute['execution']['result']['dry_run'] ?? true ), 'adapter create-draft execution returns non-dry-run ability result' );
$maa_adapter_smoke_cleanup_post_ids[] = (int) ( $draft_execute['post_id'] ?? 0 );
maa_adapter_smoke_assert( 'draft' === (string) get_post_status( (int) ( $draft_execute['post_id'] ?? 0 ) ), 'adapter approve-and-execute creates a WordPress draft' );
$duplicate_draft_execute = maa_adapter_smoke_rest_result( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $draft_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( 409 === (int) $duplicate_draft_execute['status'], 'adapter rejects duplicate create-draft approve-and-execute request' );
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_execution_already_completed' === (string) ( $duplicate_draft_execute['data']['code'] ?? '' ), 'adapter duplicate create-draft request uses completed execution error code' );
maa_adapter_smoke_assert( (int) ( $draft_execute['post_id'] ?? 0 ) === (int) ( $duplicate_draft_execute['data']['data']['execution_record']['post_id'] ?? 0 ), 'adapter duplicate create-draft returns original created post id' );

$update_post_id = maa_adapter_smoke_create_trash_post_fixture();
$maa_adapter_smoke_cleanup_post_ids[] = $update_post_id;
$update_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/update-post',
		'title'      => 'Adapter update-post approve execute smoke',
		'summary'    => 'Adapter approves through Core and executes one update-post proposal.',
		'input'      => array(
			'post_id'        => $update_post_id,
			'title'          => 'Adapter updated post smoke',
			'content'        => 'Adapter approved update-post execution smoke.',
			'content_format' => 'plain',
			'dry_run'        => true,
			'commit'         => false,
		),
		'preview'    => array(
			'action'           => 'update_post',
			'post_id'          => $update_post_id,
			'dry_run'          => true,
			'commit_execution' => false,
		),
		'caller'     => array(
			'external_thread_id' => 'adapter-update-post-approve-execute-smoke',
		),
	)
);
$update_proposal_id = (string) ( $update_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $update_proposal_id;
maa_adapter_smoke_assert( '' !== $update_proposal_id, 'adapter creates update-post proposal for approve-and-execute smoke' );
$update_execute = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $update_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( true === (bool) ( $update_execute['success'] ?? false ), 'adapter approve-and-execute succeeds for pending update-post proposal' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/update-post' === (string) ( $update_execute['ability_id'] ?? '' ), 'adapter approve-and-execute response carries update-post ability id' );
maa_adapter_smoke_assert( $update_post_id === (int) ( $update_execute['post_id'] ?? 0 ), 'adapter approve-and-execute response carries updated post id' );
maa_adapter_smoke_assert( false === (bool) ( $update_execute['execution']['result']['dry_run'] ?? true ), 'adapter update-post execution returns non-dry-run ability result' );
$updated_post = get_post( $update_post_id );
maa_adapter_smoke_assert( is_object( $updated_post ) && 'Adapter updated post smoke' === (string) $updated_post->post_title, 'adapter approve-and-execute updates post title' );
maa_adapter_smoke_assert( is_object( $updated_post ) && false !== strpos( (string) $updated_post->post_content, 'Adapter approved update-post execution smoke.' ), 'adapter approve-and-execute updates post content' );

$empty_update_proposal = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/update-post',
		'title'      => 'Adapter empty update-post smoke',
		'summary'    => 'Adapter must not execute update-post without update fields.',
		'input'      => array(
			'post_id' => $update_post_id,
			'dry_run' => true,
			'commit'  => false,
		),
		'preview'    => array(
			'action'  => 'update_post',
			'post_id' => $update_post_id,
		),
	)
);
maa_adapter_smoke_assert( 400 === (int) $empty_update_proposal['status'], 'adapter proposal create rejects update-post without update fields' );
maa_adapter_smoke_assert( 'Adapter updated post smoke' === (string) get_the_title( $update_post_id ), 'adapter empty update-post rejection leaves post unchanged' );
$proposal_create_error_event = maa_adapter_smoke_observability_event( 'adapter.proposal.create', 'error', '/npcink-openclaw-adapter/v1/proposals' );
maa_adapter_smoke_assert( ! empty( $proposal_create_error_event ), 'adapter emits proposal create failure observability event' );
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_update_fields_required' === (string) ( $proposal_create_error_event['error_code'] ?? '' ), 'adapter proposal create failure event carries stable error code' );
maa_adapter_smoke_assert( 400 === (int) ( $proposal_create_error_event['status_code'] ?? 0 ), 'adapter proposal create failure event carries status code' );
maa_adapter_smoke_assert_observability_safe( $proposal_create_error_event, 'adapter proposal create failure event' );

$invalid_update_status_proposal = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/update-post',
		'title'      => 'Adapter invalid update-post status smoke',
		'summary'    => 'Adapter must reject update-post proposal input with undeclared status.',
		'input'      => array(
			'post_id' => $update_post_id,
			'title'   => 'Adapter invalid status should not apply',
			'status'  => 'publish',
			'dry_run' => true,
			'commit'  => false,
		),
		'preview'    => array(
			'action'  => 'update_post',
			'post_id' => $update_post_id,
			'status'  => 'publish',
		),
	)
);
maa_adapter_smoke_assert( 400 === (int) $invalid_update_status_proposal['status'], 'adapter proposal create rejects update-post status input' );
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_ability_input_field_unsupported' === (string) ( $invalid_update_status_proposal['data']['code'] ?? '' ), 'adapter update-post status rejection uses schema field error code' );

$seo_post_id = maa_adapter_smoke_create_trash_post_fixture();
$maa_adapter_smoke_cleanup_post_ids[] = $seo_post_id;
$seo_title = 'Adapter SEO title ' . wp_generate_uuid4();
$seo_description = 'Adapter SEO description for approve-and-execute smoke.';
$seo_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/set-post-seo-meta',
		'title'      => 'Adapter set-post-seo-meta approve execute smoke',
		'summary'    => 'Adapter approves through Core and executes one set-post-seo-meta proposal.',
		'input'      => array(
			'post_id'         => $seo_post_id,
			'seo_title'       => $seo_title,
			'seo_description' => $seo_description,
			'dry_run'         => true,
			'commit'          => false,
		),
		'preview'    => array(
			'action'           => 'set_post_seo_meta',
			'post_id'          => $seo_post_id,
			'changed_fields'   => array( 'seo_title', 'seo_description' ),
			'dry_run'          => true,
			'commit_execution' => false,
		),
		'caller'     => array(
			'external_thread_id' => 'adapter-set-post-seo-meta-approve-execute-smoke',
		),
	)
);
$seo_proposal_id = (string) ( $seo_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $seo_proposal_id;
maa_adapter_smoke_assert( '' !== $seo_proposal_id, 'adapter creates set-post-seo-meta proposal for approve-and-execute smoke' );
$seo_execute = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $seo_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( true === (bool) ( $seo_execute['success'] ?? false ), 'adapter approve-and-execute succeeds for pending set-post-seo-meta proposal' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/set-post-seo-meta' === (string) ( $seo_execute['ability_id'] ?? '' ), 'adapter approve-and-execute response carries set-post-seo-meta ability id' );
maa_adapter_smoke_assert( $seo_post_id === (int) ( $seo_execute['post_id'] ?? 0 ), 'adapter approve-and-execute response carries SEO post id' );
maa_adapter_smoke_assert( true === (bool) ( $seo_execute['execution']['result']['updated'] ?? false ), 'adapter set-post-seo-meta execution reports update' );
maa_adapter_smoke_assert( $seo_title === (string) get_post_meta( $seo_post_id, '_yoast_wpseo_title', true ), 'adapter approve-and-execute writes SEO title meta' );
maa_adapter_smoke_assert( $seo_description === (string) get_post_meta( $seo_post_id, '_yoast_wpseo_metadesc', true ), 'adapter approve-and-execute writes SEO description meta' );

$empty_seo_proposal = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/set-post-seo-meta',
		'title'      => 'Adapter empty set-post-seo-meta smoke',
		'summary'    => 'Adapter must not execute set-post-seo-meta without SEO fields.',
		'input'      => array(
			'post_id' => $seo_post_id,
			'dry_run' => true,
			'commit'  => false,
		),
		'preview'    => array(
			'action'  => 'set_post_seo_meta',
			'post_id' => $seo_post_id,
		),
	)
);
maa_adapter_smoke_assert( 400 === (int) $empty_seo_proposal['status'], 'adapter proposal create rejects set-post-seo-meta without SEO fields' );

$slug_post_id = maa_adapter_smoke_create_trash_post_fixture();
$maa_adapter_smoke_cleanup_post_ids[] = $slug_post_id;
$slug = 'adapter-slug-smoke-' . substr( md5( wp_generate_uuid4() ), 0, 12 );
$slug_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/set-post-slug',
		'title'      => 'Adapter set-post-slug approve execute smoke',
		'summary'    => 'Adapter approves through Core and executes one set-post-slug proposal.',
		'input'      => array(
			'post_id' => $slug_post_id,
			'slug'    => $slug,
			'dry_run' => true,
			'commit'  => false,
		),
		'preview'    => array(
			'action'           => 'set_post_slug',
			'post_id'          => $slug_post_id,
			'slug'             => $slug,
			'dry_run'          => true,
			'commit_execution' => false,
		),
		'caller'     => array(
			'external_thread_id' => 'adapter-set-post-slug-approve-execute-smoke',
		),
	)
);
$slug_proposal_id = (string) ( $slug_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $slug_proposal_id;
maa_adapter_smoke_assert( '' !== $slug_proposal_id, 'adapter creates set-post-slug proposal for approve-and-execute smoke' );
$slug_execute = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $slug_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( true === (bool) ( $slug_execute['success'] ?? false ), 'adapter approve-and-execute succeeds for pending set-post-slug proposal' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/set-post-slug' === (string) ( $slug_execute['ability_id'] ?? '' ), 'adapter approve-and-execute response carries set-post-slug ability id' );
maa_adapter_smoke_assert( $slug_post_id === (int) ( $slug_execute['post_id'] ?? 0 ), 'adapter approve-and-execute response carries slug post id' );
maa_adapter_smoke_assert( $slug === (string) get_post_field( 'post_name', $slug_post_id ), 'adapter approve-and-execute writes post slug' );

$empty_slug_proposal = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/set-post-slug',
		'title'      => 'Adapter empty set-post-slug smoke',
		'summary'    => 'Adapter must not execute set-post-slug without a valid slug.',
		'input'      => array(
			'post_id' => $slug_post_id,
			'slug'    => '!!!',
			'dry_run' => true,
			'commit'  => false,
		),
		'preview'    => array(
			'action'  => 'set_post_slug',
			'post_id' => $slug_post_id,
		),
	)
);
maa_adapter_smoke_assert( 400 === (int) $empty_slug_proposal['status'], 'adapter proposal create rejects set-post-slug without valid slug' );

$set_terms_post_id = maa_adapter_smoke_create_trash_post_fixture();
$maa_adapter_smoke_cleanup_post_ids[] = $set_terms_post_id;
$set_terms_term = wp_insert_term( 'Adapter Terms Smoke ' . wp_generate_uuid4(), 'post_tag' );
maa_adapter_smoke_assert( ! is_wp_error( $set_terms_term ) && (int) ( $set_terms_term['term_id'] ?? 0 ) > 0, 'adapter smoke created post_tag term fixture' );
$set_terms_term_id = (int) $set_terms_term['term_id'];
$maa_adapter_smoke_cleanup_terms[] = array(
	'term_id'  => $set_terms_term_id,
	'taxonomy' => 'post_tag',
);
$set_terms_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/set-post-terms',
		'title'      => 'Adapter set-post-terms approve execute smoke',
		'summary'    => 'Adapter approves through Core and executes one set-post-terms proposal.',
		'input'      => array(
			'post_id'        => $set_terms_post_id,
			'taxonomy'       => 'post_tag',
			'mode'           => 'append',
			'term_ids'       => array( $set_terms_term_id ),
			'create_missing' => false,
			'dry_run'        => true,
			'commit'         => false,
		),
		'preview'    => array(
			'action'           => 'set_post_terms',
			'post_id'          => $set_terms_post_id,
			'taxonomy'         => 'post_tag',
			'mode'             => 'append',
			'term_ids'         => array( $set_terms_term_id ),
			'dry_run'          => true,
			'commit_execution' => false,
		),
		'caller'     => array(
			'external_thread_id' => 'adapter-set-post-terms-approve-execute-smoke',
		),
	)
);
$set_terms_proposal_id = (string) ( $set_terms_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $set_terms_proposal_id;
maa_adapter_smoke_assert( '' !== $set_terms_proposal_id, 'adapter creates set-post-terms proposal for approve-and-execute smoke' );
$set_terms_execute = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $set_terms_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( true === (bool) ( $set_terms_execute['success'] ?? false ), 'adapter approve-and-execute succeeds for pending set-post-terms proposal' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/set-post-terms' === (string) ( $set_terms_execute['ability_id'] ?? '' ), 'adapter approve-and-execute response carries set-post-terms ability id' );
maa_adapter_smoke_assert( $set_terms_post_id === (int) ( $set_terms_execute['post_id'] ?? 0 ), 'adapter approve-and-execute response carries terms post id' );
maa_adapter_smoke_assert( false === (bool) ( $set_terms_execute['execution']['result']['dry_run'] ?? true ), 'adapter set-post-terms execution returns non-dry-run ability result' );
$assigned_term_ids = wp_get_post_terms( $set_terms_post_id, 'post_tag', array( 'fields' => 'ids' ) );
maa_adapter_smoke_assert( ! is_wp_error( $assigned_term_ids ) && in_array( $set_terms_term_id, array_map( 'intval', (array) $assigned_term_ids ), true ), 'adapter approve-and-execute assigns existing post term' );
$duplicate_set_terms_execute = maa_adapter_smoke_rest_result( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $set_terms_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( 409 === (int) $duplicate_set_terms_execute['status'], 'adapter rejects duplicate set-post-terms approve-and-execute request' );
maa_adapter_smoke_assert( 'npcink_openclaw_adapter_execution_already_completed' === (string) ( $duplicate_set_terms_execute['data']['code'] ?? '' ), 'adapter duplicate set-post-terms request uses completed execution error code' );
maa_adapter_smoke_assert( $set_terms_post_id === (int) ( $duplicate_set_terms_execute['data']['data']['execution_record']['post_id'] ?? 0 ), 'adapter duplicate set-post-terms returns original post id' );

$empty_terms_proposal = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/set-post-terms',
		'title'      => 'Adapter empty set-post-terms smoke',
		'summary'    => 'Adapter must not execute set-post-terms without terms.',
		'input'      => array(
			'post_id'  => $set_terms_post_id,
			'taxonomy' => 'post_tag',
			'mode'     => 'append',
			'dry_run'  => true,
			'commit'   => false,
		),
		'preview'    => array(
			'action'  => 'set_post_terms',
			'post_id' => $set_terms_post_id,
		),
	)
);
maa_adapter_smoke_assert( 400 === (int) $empty_terms_proposal['status'], 'adapter proposal create rejects set-post-terms without terms' );

$create_missing_terms_proposal = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/set-post-terms',
		'title'      => 'Adapter create-missing terms smoke',
		'summary'    => 'Adapter must not create missing terms during set-post-terms execution.',
		'input'      => array(
			'post_id'        => $set_terms_post_id,
			'taxonomy'       => 'post_tag',
			'mode'           => 'append',
			'terms'          => array( 'Adapter Missing Term Smoke' ),
			'create_missing' => true,
			'dry_run'        => true,
			'commit'         => false,
		),
		'preview'    => array(
			'action'         => 'set_post_terms',
			'post_id'        => $set_terms_post_id,
			'create_missing' => true,
		),
	)
);
maa_adapter_smoke_assert( 400 === (int) $create_missing_terms_proposal['status'], 'adapter proposal create rejects set-post-terms create_missing' );

$delete_term = wp_insert_term( 'Adapter Delete Term Smoke ' . wp_generate_uuid4(), 'post_tag' );
maa_adapter_smoke_assert( ! is_wp_error( $delete_term ) && (int) ( $delete_term['term_id'] ?? 0 ) > 0, 'adapter smoke created delete-term fixture' );
$delete_term_id = (int) $delete_term['term_id'];
$maa_adapter_smoke_cleanup_terms[] = array(
	'term_id'  => $delete_term_id,
	'taxonomy' => 'post_tag',
);
$delete_term_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/delete-term',
		'title'      => 'Adapter delete-term approve execute smoke',
		'summary'    => 'Adapter approves through Core and executes one delete-term proposal.',
		'input'      => array(
			'taxonomy' => 'post_tag',
			'term_id'  => $delete_term_id,
			'dry_run'  => true,
			'commit'   => false,
		),
		'preview'    => array(
			'action'           => 'delete_term',
			'taxonomy'         => 'post_tag',
			'term_id'          => $delete_term_id,
			'dry_run'          => true,
			'commit_execution' => false,
		),
		'caller'     => array(
			'external_thread_id' => 'adapter-delete-term-approve-execute-smoke',
		),
	)
);
$delete_term_proposal_id = (string) ( $delete_term_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $delete_term_proposal_id;
maa_adapter_smoke_assert( '' !== $delete_term_proposal_id, 'adapter creates delete-term proposal for approve-and-execute smoke' );
$delete_term_execute = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $delete_term_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( true === (bool) ( $delete_term_execute['success'] ?? false ), 'adapter approve-and-execute succeeds for pending delete-term proposal' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/delete-term' === (string) ( $delete_term_execute['ability_id'] ?? '' ), 'adapter approve-and-execute response carries delete-term ability id' );
maa_adapter_smoke_assert( false === (bool) ( $delete_term_execute['execution']['result']['dry_run'] ?? true ), 'adapter delete-term execution returns non-dry-run ability result' );
maa_adapter_smoke_assert( true === (bool) ( $delete_term_execute['execution']['result']['deleted'] ?? false ), 'adapter delete-term execution reports deletion' );
maa_adapter_smoke_assert( ! term_exists( $delete_term_id, 'post_tag' ), 'adapter approve-and-execute deletes unused term' );

$empty_delete_term_proposal = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/delete-term',
		'title'      => 'Adapter empty delete-term smoke',
		'summary'    => 'Adapter must not execute delete-term without term_id.',
		'input'      => array(
			'taxonomy' => 'post_tag',
			'dry_run'  => true,
			'commit'   => false,
		),
		'preview'    => array(
			'action'   => 'delete_term',
			'taxonomy' => 'post_tag',
		),
	)
);
maa_adapter_smoke_assert( 400 === (int) $empty_delete_term_proposal['status'], 'adapter proposal create rejects delete-term without term_id' );

$invalid_taxonomy_delete_term_proposal = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/delete-term',
		'title'      => 'Adapter invalid taxonomy delete-term smoke',
		'summary'    => 'Adapter must not execute delete-term without a valid taxonomy.',
		'input'      => array(
			'taxonomy' => 'adapter_missing_taxonomy',
			'term_id'  => $delete_term_id,
			'dry_run'  => true,
			'commit'   => false,
		),
		'preview'    => array(
			'action'   => 'delete_term',
			'taxonomy' => 'adapter_missing_taxonomy',
			'term_id'  => $delete_term_id,
		),
	)
);
maa_adapter_smoke_assert( 400 === (int) $invalid_taxonomy_delete_term_proposal['status'], 'adapter proposal create rejects delete-term invalid taxonomy' );

$media_details_attachment_id = maa_adapter_smoke_create_media_plan_attachment();
$maa_adapter_smoke_cleanup_attachment_ids[] = $media_details_attachment_id;
$media_details_title = 'Adapter media details smoke ' . wp_generate_uuid4();
$media_details_alt = 'Adapter media details alt smoke.';
$media_details_copyright = 'Generated asset for this site';
$media_details_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/update-media-details',
		'title'      => 'Adapter update-media-details approve execute smoke',
		'summary'    => 'Adapter approves through Core and executes one update-media-details proposal.',
		'input'      => array(
			'attachment_id' => $media_details_attachment_id,
			'title'         => $media_details_title,
			'alt'           => $media_details_alt,
			'source_type'   => 'ai_generated',
			'copyright_notice' => $media_details_copyright,
			'dry_run'       => true,
			'commit'        => false,
		),
		'preview'    => array(
			'action'           => 'update_media_details',
			'attachment_id'    => $media_details_attachment_id,
			'changed_fields'   => array( 'title', 'alt', 'source_type', 'copyright_notice' ),
			'dry_run'          => true,
			'commit_execution' => false,
		),
		'caller'     => array(
			'external_thread_id' => 'adapter-update-media-details-approve-execute-smoke',
		),
	)
);
$media_details_proposal_id = (string) ( $media_details_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $media_details_proposal_id;
maa_adapter_smoke_assert( '' !== $media_details_proposal_id, 'adapter creates update-media-details proposal for approve-and-execute smoke' );
$media_details_execute = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $media_details_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( true === (bool) ( $media_details_execute['success'] ?? false ), 'adapter approve-and-execute succeeds for pending update-media-details proposal' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/update-media-details' === (string) ( $media_details_execute['ability_id'] ?? '' ), 'adapter approve-and-execute response carries update-media-details ability id' );
maa_adapter_smoke_assert( $media_details_attachment_id === (int) ( $media_details_execute['execution']['result']['attachment_id'] ?? 0 ), 'adapter update-media-details execution result carries attachment id' );
maa_adapter_smoke_assert( true === (bool) ( $media_details_execute['execution']['result']['updated'] ?? false ), 'adapter update-media-details execution reports update' );
maa_adapter_smoke_assert( $media_details_title === (string) get_the_title( $media_details_attachment_id ), 'adapter approve-and-execute updates media title' );
maa_adapter_smoke_assert( $media_details_alt === (string) get_post_meta( $media_details_attachment_id, '_wp_attachment_image_alt', true ), 'adapter approve-and-execute updates media alt text' );
maa_adapter_smoke_assert( 'ai_generated' === (string) get_post_meta( $media_details_attachment_id, '_npcink_ai_media_source_type', true ), 'adapter approve-and-execute updates media source type' );
maa_adapter_smoke_assert( $media_details_copyright === (string) get_post_meta( $media_details_attachment_id, '_npcink_ai_media_copyright_notice', true ), 'adapter approve-and-execute updates media copyright notice' );

$empty_media_details_proposal = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/update-media-details',
		'title'      => 'Adapter empty update-media-details smoke',
		'summary'    => 'Adapter must not execute update-media-details without detail fields.',
		'input'      => array(
			'attachment_id' => $media_details_attachment_id,
			'dry_run'       => true,
			'commit'        => false,
		),
		'preview'    => array(
			'action'        => 'update_media_details',
			'attachment_id' => $media_details_attachment_id,
		),
	)
);
maa_adapter_smoke_assert( 400 === (int) $empty_media_details_proposal['status'], 'adapter proposal create rejects update-media-details without detail fields' );

$rename_dry_attachment_id = maa_adapter_smoke_create_real_media_attachment();
$rename_dry_before_relative = (string) get_post_meta( $rename_dry_attachment_id, '_wp_attached_file', true );
$rename_dry_before_path = (string) get_attached_file( $rename_dry_attachment_id );
$rename_dry_before_url = (string) wp_get_attachment_url( $rename_dry_attachment_id );
$rename_dry_target_name = 'adapter-rename-dry-target-' . substr( wp_generate_uuid4(), 0, 8 ) . '.png';
$rename_dry_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/rename-media-file',
		'title'      => 'Adapter rename-media-file dry-run preflight smoke',
		'summary'    => 'Adapter approves and preflights rename-media-file without executing the final write.',
		'input'      => array(
			'attachment_id'                  => $rename_dry_attachment_id,
			'target_file_name'               => $rename_dry_target_name,
			'expected_current_relative_file' => $rename_dry_before_relative,
			'expected_current_mime_type'     => 'image/png',
			'expected_current_md5'           => md5_file( $rename_dry_before_path ),
			'conflict_mode'                  => 'fail',
			'dry_run'                        => true,
			'commit'                         => false,
		),
		'preview'    => array(
			'action'           => 'rename_media_file',
			'attachment_id'    => $rename_dry_attachment_id,
			'target_file_name' => $rename_dry_target_name,
			'dry_run'          => true,
			'commit_execution' => false,
		),
		'caller'     => array(
			'external_thread_id' => 'adapter-rename-media-file-dry-run-preflight-smoke',
		),
	)
);
$rename_dry_proposal_id = (string) ( $rename_dry_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $rename_dry_proposal_id;
maa_adapter_smoke_assert( '' !== $rename_dry_proposal_id, 'adapter creates rename-media-file proposal for dry-run preflight smoke' );
$rename_dry_approved = maa_adapter_smoke_rest(
	'POST',
	'/npcink-governance-core/v1/proposals/' . rawurlencode( $rename_dry_proposal_id ) . '/approve',
	array( 'note' => 'Adapter rename dry-run preflight smoke approval.' )
);
maa_adapter_smoke_assert( 'approved' === (string) ( $rename_dry_approved['status'] ?? '' ), 'Core admin REST approval succeeds for rename-media-file dry-run preflight smoke' );
$rename_dry_preflight = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $rename_dry_proposal_id ) . '/commit-preflight' );
maa_adapter_smoke_assert( false === (bool) ( $rename_dry_preflight['commit_execution'] ?? true ), 'adapter rename-media-file dry-run preflight preserves Core commit_execution=false' );
maa_adapter_smoke_assert( true === (bool) ( $rename_dry_preflight['adapter_preflight_handoff_cached'] ?? false ), 'adapter rename-media-file dry-run preflight caches handoff without executing' );
maa_adapter_smoke_assert( $rename_dry_before_relative === (string) get_post_meta( $rename_dry_attachment_id, '_wp_attached_file', true ), 'adapter rename-media-file dry-run preflight leaves attached file pointer unchanged' );
maa_adapter_smoke_assert( $rename_dry_before_url === (string) wp_get_attachment_url( $rename_dry_attachment_id ), 'adapter rename-media-file dry-run preflight leaves media URL unchanged' );
maa_adapter_smoke_assert( is_readable( $rename_dry_before_path ), 'adapter rename-media-file dry-run preflight leaves original media file in place' );

$rename_commit_attachment_id = maa_adapter_smoke_create_real_media_attachment();
$rename_commit_before_relative = (string) get_post_meta( $rename_commit_attachment_id, '_wp_attached_file', true );
$rename_commit_before_path = (string) get_attached_file( $rename_commit_attachment_id );
$rename_commit_before_url = (string) wp_get_attachment_url( $rename_commit_attachment_id );
$rename_commit_target_name = 'adapter-rename-commit-target-' . substr( wp_generate_uuid4(), 0, 8 ) . '.png';
$rename_commit_target_relative = trailingslashit( trim( dirname( $rename_commit_before_relative ), './' ) ) . $rename_commit_target_name;
$rename_commit_target_relative = ltrim( $rename_commit_target_relative, '/' );
$rename_commit_uploads = wp_upload_dir();
$rename_commit_target_path = trailingslashit( (string) ( $rename_commit_uploads['basedir'] ?? '' ) ) . $rename_commit_target_relative;
maa_adapter_smoke_assert( ! file_exists( $rename_commit_target_path ), 'adapter rename-media-file commit target file starts absent' );
$rename_commit_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/rename-media-file',
		'title'      => 'Adapter rename-media-file approve execute smoke',
		'summary'    => 'Adapter approves through Core and executes one rename-media-file proposal.',
		'input'      => array(
			'attachment_id'                  => $rename_commit_attachment_id,
			'target_file_name'               => $rename_commit_target_name,
			'expected_current_relative_file' => $rename_commit_before_relative,
			'expected_current_mime_type'     => 'image/png',
			'expected_current_md5'           => md5_file( $rename_commit_before_path ),
			'conflict_mode'                  => 'fail',
			'dry_run'                        => true,
			'commit'                         => false,
		),
		'preview'    => array(
			'action'           => 'rename_media_file',
			'attachment_id'    => $rename_commit_attachment_id,
			'target_file_name' => $rename_commit_target_name,
			'dry_run'          => true,
			'commit_execution' => false,
		),
		'caller'     => array(
			'external_thread_id' => 'adapter-rename-media-file-approve-execute-smoke',
		),
	)
);
$rename_commit_proposal_id = (string) ( $rename_commit_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $rename_commit_proposal_id;
maa_adapter_smoke_assert( '' !== $rename_commit_proposal_id, 'adapter creates rename-media-file proposal for approve-and-execute smoke' );
$rename_commit_execute = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $rename_commit_proposal_id ) . '/approve-and-execute' );
$rename_commit_result = is_array( $rename_commit_execute['execution']['result'] ?? null ) ? $rename_commit_execute['execution']['result'] : array();
maa_adapter_smoke_assert( true === (bool) ( $rename_commit_execute['success'] ?? false ), 'adapter approve-and-execute succeeds for pending rename-media-file proposal' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/rename-media-file' === (string) ( $rename_commit_execute['ability_id'] ?? '' ), 'adapter approve-and-execute response carries rename-media-file ability id' );
maa_adapter_smoke_assert( false === (bool) ( $rename_commit_result['dry_run'] ?? true ), 'adapter rename-media-file execution returns non-dry-run ability result' );
maa_adapter_smoke_assert( true === (bool) ( $rename_commit_result['renamed'] ?? false ), 'adapter rename-media-file execution reports rename' );
maa_adapter_smoke_assert( $rename_commit_target_relative === (string) get_post_meta( $rename_commit_attachment_id, '_wp_attached_file', true ), 'adapter rename-media-file commit updates attached file pointer' );
maa_adapter_smoke_assert( false === is_readable( $rename_commit_before_path ), 'adapter rename-media-file commit moves old media file' );
maa_adapter_smoke_assert( is_readable( $rename_commit_target_path ), 'adapter rename-media-file commit writes target media file' );
maa_adapter_smoke_assert( $rename_commit_before_url !== (string) wp_get_attachment_url( $rename_commit_attachment_id ), 'adapter rename-media-file commit changes media URL' );
maa_adapter_smoke_assert( false !== strpos( (string) wp_get_attachment_url( $rename_commit_attachment_id ), $rename_commit_target_name ), 'adapter rename-media-file commit URL contains target file name' );
$rename_commit_backup_relative = (string) ( $rename_commit_result['backup']['relative_file'] ?? '' );
if ( '' !== $rename_commit_backup_relative ) {
	@unlink( trailingslashit( (string) ( $rename_commit_uploads['basedir'] ?? '' ) ) . ltrim( $rename_commit_backup_relative, '/' ) );
}

$delete_media_attachment_id = maa_adapter_smoke_create_media_plan_attachment();
$maa_adapter_smoke_cleanup_attachment_ids[] = $delete_media_attachment_id;
$delete_media_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/delete-media-permanently',
		'title'      => 'Adapter delete-media-permanently approve execute smoke',
		'summary'    => 'Adapter approves through Core and executes one delete-media-permanently proposal.',
		'input'      => array(
			'attachment_id' => $delete_media_attachment_id,
			'dry_run'       => true,
			'commit'        => false,
		),
		'preview'    => array(
			'action'           => 'delete_media_permanently',
			'attachment_id'    => $delete_media_attachment_id,
			'dry_run'          => true,
			'commit_execution' => false,
		),
		'caller'     => array(
			'external_thread_id' => 'adapter-delete-media-permanently-approve-execute-smoke',
		),
	)
);
$delete_media_proposal_id = (string) ( $delete_media_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $delete_media_proposal_id;
maa_adapter_smoke_assert( '' !== $delete_media_proposal_id, 'adapter creates delete-media-permanently proposal for approve-and-execute smoke' );
$delete_media_execute = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $delete_media_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( true === (bool) ( $delete_media_execute['success'] ?? false ), 'adapter approve-and-execute succeeds for pending delete-media-permanently proposal' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/delete-media-permanently' === (string) ( $delete_media_execute['ability_id'] ?? '' ), 'adapter approve-and-execute response carries delete-media-permanently ability id' );
maa_adapter_smoke_assert( $delete_media_attachment_id === (int) ( $delete_media_execute['execution']['result']['attachment_id'] ?? 0 ), 'adapter delete-media-permanently execution result carries attachment id' );
maa_adapter_smoke_assert( true === (bool) ( $delete_media_execute['execution']['result']['deleted'] ?? false ), 'adapter delete-media-permanently execution reports deletion' );
maa_adapter_smoke_assert( null === get_post( $delete_media_attachment_id ), 'adapter approve-and-execute deletes media attachment permanently' );

$empty_delete_media_proposal = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/delete-media-permanently',
		'title'      => 'Adapter empty delete-media-permanently smoke',
		'summary'    => 'Adapter must not execute delete-media-permanently without attachment_id.',
		'input'      => array(
			'dry_run' => true,
			'commit'  => false,
		),
		'preview'    => array(
			'action' => 'delete_media_permanently',
		),
	)
);
maa_adapter_smoke_assert( 400 === (int) $empty_delete_media_proposal['status'], 'adapter proposal create rejects delete-media-permanently without attachment_id' );

$non_attachment_delete_media_post_id = maa_adapter_smoke_create_trash_post_fixture();
$maa_adapter_smoke_cleanup_post_ids[] = $non_attachment_delete_media_post_id;
$non_attachment_delete_media_proposal = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/delete-media-permanently',
		'title'      => 'Adapter non-attachment delete-media-permanently smoke',
		'summary'    => 'Adapter must not execute delete-media-permanently for non-attachment posts.',
		'input'      => array(
			'attachment_id' => $non_attachment_delete_media_post_id,
			'dry_run'       => true,
			'commit'        => false,
		),
		'preview'    => array(
			'action'        => 'delete_media_permanently',
			'attachment_id' => $non_attachment_delete_media_post_id,
		),
	)
);
maa_adapter_smoke_assert( 400 === (int) $non_attachment_delete_media_proposal['status'], 'adapter proposal create rejects delete-media-permanently for non-attachment post' );

$reply_post_id = maa_adapter_smoke_create_trash_post_fixture();
$maa_adapter_smoke_cleanup_post_ids[] = $reply_post_id;
$reply_parent_comment_id = maa_adapter_smoke_create_comment_fixture( $reply_post_id );
$maa_adapter_smoke_cleanup_comment_ids[] = $reply_parent_comment_id;
$reply_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/reply-comment',
		'title'      => 'Adapter reply-comment approve execute smoke',
		'summary'    => 'Adapter approves through Core and executes one reply-comment proposal.',
		'input'      => array(
			'comment_id'     => $reply_parent_comment_id,
			'content'        => 'Adapter approved reply-comment execution smoke.',
			'content_format' => 'plain',
			'dry_run'        => true,
			'commit'         => false,
		),
		'preview'    => array(
			'action'           => 'reply_comment',
			'comment_id'       => $reply_parent_comment_id,
			'content_format'   => 'plain',
			'dry_run'          => true,
			'commit_execution' => false,
		),
		'caller'     => array(
			'external_thread_id' => 'adapter-reply-comment-approve-execute-smoke',
		),
	)
);
$reply_proposal_id = (string) ( $reply_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $reply_proposal_id;
maa_adapter_smoke_assert( '' !== $reply_proposal_id, 'adapter creates reply-comment proposal for approve-and-execute smoke' );
$reply_execute = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $reply_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( true === (bool) ( $reply_execute['success'] ?? false ), 'adapter approve-and-execute succeeds for pending reply-comment proposal' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/reply-comment' === (string) ( $reply_execute['ability_id'] ?? '' ), 'adapter approve-and-execute response carries reply-comment ability id' );
maa_adapter_smoke_assert( $reply_post_id === (int) ( $reply_execute['post_id'] ?? 0 ), 'adapter approve-and-execute response carries reply post id' );
maa_adapter_smoke_assert( false === (bool) ( $reply_execute['execution']['result']['dry_run'] ?? true ), 'adapter reply-comment execution returns non-dry-run ability result' );
$reply_comment_id = (int) ( $reply_execute['execution']['result']['comment_id'] ?? 0 );
$maa_adapter_smoke_cleanup_comment_ids[] = $reply_comment_id;
$reply_comment = get_comment( $reply_comment_id );
maa_adapter_smoke_assert( $reply_comment instanceof WP_Comment, 'adapter approve-and-execute creates reply comment' );
maa_adapter_smoke_assert( $reply_parent_comment_id === (int) ( $reply_comment->comment_parent ?? 0 ), 'adapter reply-comment uses original comment as parent' );
maa_adapter_smoke_assert( $reply_post_id === (int) ( $reply_comment->comment_post_ID ?? 0 ), 'adapter reply-comment stays on original post' );

$empty_reply_proposal = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/reply-comment',
		'title'      => 'Adapter empty reply-comment smoke',
		'summary'    => 'Adapter must not execute reply-comment without content.',
		'input'      => array(
			'comment_id' => $reply_parent_comment_id,
			'content'    => '   ',
			'dry_run'    => true,
			'commit'     => false,
		),
		'preview'    => array(
			'action'     => 'reply_comment',
			'comment_id' => $reply_parent_comment_id,
		),
	)
);
maa_adapter_smoke_assert( 400 === (int) $empty_reply_proposal['status'], 'adapter proposal create rejects reply-comment without content' );

$approve_comment_post_id = maa_adapter_smoke_create_trash_post_fixture();
$maa_adapter_smoke_cleanup_post_ids[] = $approve_comment_post_id;
$approve_comment_id = maa_adapter_smoke_create_comment_fixture( $approve_comment_post_id, 'Adapter approve-comment pending smoke.', '0' );
$maa_adapter_smoke_cleanup_comment_ids[] = $approve_comment_id;
$approve_comment_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/approve-comment',
		'title'      => 'Adapter approve-comment approve execute smoke',
		'summary'    => 'Adapter approves through Core and executes one approve-comment proposal.',
		'input'      => array(
			'comment_id' => $approve_comment_id,
			'dry_run'    => true,
			'commit'     => false,
		),
		'preview'    => array(
			'action'           => 'approve_comment',
			'comment_id'       => $approve_comment_id,
			'dry_run'          => true,
			'commit_execution' => false,
		),
		'caller'     => array(
			'external_thread_id' => 'adapter-approve-comment-approve-execute-smoke',
		),
	)
);
$approve_comment_proposal_id = (string) ( $approve_comment_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $approve_comment_proposal_id;
maa_adapter_smoke_assert( '' !== $approve_comment_proposal_id, 'adapter creates approve-comment proposal for approve-and-execute smoke' );
$approve_comment_execute = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $approve_comment_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( true === (bool) ( $approve_comment_execute['success'] ?? false ), 'adapter approve-and-execute succeeds for pending approve-comment proposal' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/approve-comment' === (string) ( $approve_comment_execute['ability_id'] ?? '' ), 'adapter approve-and-execute response carries approve-comment ability id' );
maa_adapter_smoke_assert( $approve_comment_post_id === (int) ( $approve_comment_execute['post_id'] ?? 0 ), 'adapter approve-and-execute response carries approve-comment post id' );
maa_adapter_smoke_assert( false === (bool) ( $approve_comment_execute['execution']['result']['dry_run'] ?? true ), 'adapter approve-comment execution returns non-dry-run ability result' );
maa_adapter_smoke_assert( true === (bool) ( $approve_comment_execute['execution']['result']['updated'] ?? false ), 'adapter approve-comment execution reports update' );
maa_adapter_smoke_assert( 'approved' === wp_get_comment_status( $approve_comment_id ), 'adapter approve-and-execute approves pending comment' );

$empty_approve_comment_proposal = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/approve-comment',
		'title'      => 'Adapter empty approve-comment smoke',
		'summary'    => 'Adapter must not execute approve-comment without comment_id.',
		'input'      => array(
			'comment_id' => 0,
			'dry_run'    => true,
			'commit'     => false,
		),
		'preview'    => array(
			'action'     => 'approve_comment',
			'comment_id' => 0,
		),
	)
);
maa_adapter_smoke_assert( 400 === (int) $empty_approve_comment_proposal['status'], 'adapter proposal create rejects approve-comment without comment_id' );

$trash_comment_post_id = maa_adapter_smoke_create_trash_post_fixture();
$maa_adapter_smoke_cleanup_post_ids[] = $trash_comment_post_id;
$trash_comment_id = maa_adapter_smoke_create_comment_fixture( $trash_comment_post_id, 'Adapter trash-comment smoke.' );
$maa_adapter_smoke_cleanup_comment_ids[] = $trash_comment_id;
$trash_comment_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/trash-comment',
		'title'      => 'Adapter trash-comment approve execute smoke',
		'summary'    => 'Adapter approves through Core and executes one trash-comment proposal.',
		'input'      => array(
			'comment_id' => $trash_comment_id,
			'dry_run'    => true,
			'commit'     => false,
		),
		'preview'    => array(
			'action'           => 'trash_comment',
			'comment_id'       => $trash_comment_id,
			'dry_run'          => true,
			'commit_execution' => false,
		),
		'caller'     => array(
			'external_thread_id' => 'adapter-trash-comment-approve-execute-smoke',
		),
	)
);
$trash_comment_proposal_id = (string) ( $trash_comment_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $trash_comment_proposal_id;
maa_adapter_smoke_assert( '' !== $trash_comment_proposal_id, 'adapter creates trash-comment proposal for approve-and-execute smoke' );
$trash_comment_execute = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $trash_comment_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( true === (bool) ( $trash_comment_execute['success'] ?? false ), 'adapter approve-and-execute succeeds for pending trash-comment proposal' );
maa_adapter_smoke_assert( 'npcink-abilities-toolkit/trash-comment' === (string) ( $trash_comment_execute['ability_id'] ?? '' ), 'adapter approve-and-execute response carries trash-comment ability id' );
maa_adapter_smoke_assert( $trash_comment_post_id === (int) ( $trash_comment_execute['post_id'] ?? 0 ), 'adapter approve-and-execute response carries trash-comment post id' );
maa_adapter_smoke_assert( 'trash' === wp_get_comment_status( $trash_comment_id ), 'adapter approve-and-execute trashes comment' );

$empty_trash_comment_proposal = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/trash-comment',
		'title'      => 'Adapter empty trash-comment smoke',
		'summary'    => 'Adapter must not execute trash-comment without comment_id.',
		'input'      => array(
			'comment_id' => 0,
			'dry_run'    => true,
			'commit'     => false,
		),
		'preview'    => array(
			'action'     => 'trash_comment',
			'comment_id' => 0,
		),
	)
);
maa_adapter_smoke_assert( 400 === (int) $empty_trash_comment_proposal['status'], 'adapter proposal create rejects trash-comment without comment_id' );

$unallowed_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/set-post-author',
		'title'      => 'Adapter unallowed approve execute smoke',
		'summary'    => 'Adapter must not approve-and-execute non-supported proposals.',
		'input'      => array(
			'post_id'   => $blocked_post_id,
			'author_id' => 1,
			'dry_run'   => true,
			'commit'    => false,
		),
		'preview'    => array(),
	)
);
$unallowed_proposal_id = (string) ( $unallowed_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $unallowed_proposal_id;
$unallowed_approve_execute = maa_adapter_smoke_rest_result( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $unallowed_proposal_id ) . '/approve-and-execute' );
maa_adapter_smoke_assert( 403 === (int) $unallowed_approve_execute['status'], 'adapter approve-and-execute rejects non-supported ability' );

$proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/create-draft',
		'title'      => 'Adapter proposal status smoke',
		'summary'    => 'Proposal status relay smoke, no final write.',
		'input'      => array(
			'title'   => 'Adapter proposal status smoke',
			'dry_run' => true,
			'commit'  => false,
		),
		'preview'    => array(
			'mode' => 'adapter_status_smoke',
		),
		'caller'     => array(
			'external_thread_id' => 'adapter-status-smoke',
		),
	)
);
$proposal_id = (string) ( $proposal['proposal_id'] ?? '' );
maa_adapter_smoke_assert( '' !== $proposal_id, 'adapter creates Core proposal for status smoke' );
maa_adapter_smoke_assert( 'pending' === (string) ( $proposal['status'] ?? '' ), 'adapter created proposal starts pending' );
maa_adapter_smoke_assert( 'openclaw_adapter' === (string) ( $proposal['caller']['caller_type'] ?? '' ), 'adapter proposal caller marks OpenClaw adapter' );
maa_adapter_smoke_assert( 'npcink-ai-client-adapter' === (string) ( $proposal['caller']['via'] ?? '' ), 'adapter proposal caller preserves adapter source' );

$proposal_list = maa_adapter_smoke_rest(
	'GET',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'limit' => 50,
	)
);
$found_proposal = false;
foreach ( (array) ( $proposal_list['items'] ?? array() ) as $item ) {
	if ( is_array( $item ) && $proposal_id === (string) ( $item['proposal_id'] ?? '' ) ) {
		$found_proposal = true;
		maa_adapter_smoke_assert( 'npcink-abilities-toolkit/create-draft' === (string) ( $item['ability_id'] ?? '' ), 'adapter proposal list preserves ability id' );
		maa_adapter_smoke_assert( 'pending' === (string) ( $item['status'] ?? '' ), 'adapter proposal list preserves status' );
		break;
	}
}
maa_adapter_smoke_assert( $found_proposal, 'adapter returns created proposal in Core proposal list' );

$proposal_detail = maa_adapter_smoke_rest( 'GET', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $proposal_id ) );
maa_adapter_smoke_assert( $proposal_id === (string) ( $proposal_detail['proposal_id'] ?? '' ), 'adapter returns proposal detail through Core' );
maa_adapter_smoke_assert( 'pending' === (string) ( $proposal_detail['status'] ?? '' ), 'adapter proposal detail preserves status' );
maa_adapter_smoke_assert( 'Adapter proposal status smoke' === (string) ( $proposal_detail['title'] ?? '' ), 'adapter proposal detail preserves title' );
maa_adapter_smoke_assert( is_array( $proposal_detail['input'] ?? null ), 'adapter proposal detail preserves input' );
maa_adapter_smoke_assert( is_array( $proposal_detail['preview'] ?? null ), 'adapter proposal detail preserves preview' );
maa_adapter_smoke_assert( is_array( $proposal_detail['caller'] ?? null ), 'adapter proposal detail preserves caller' );
maa_adapter_smoke_assert( is_array( $proposal_detail['audit_timeline'] ?? null ), 'adapter proposal detail preserves audit timeline' );

$approval_route_result = maa_adapter_smoke_rest_result( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $proposal_id ) . '/approve' );
maa_adapter_smoke_assert( 404 === (int) $approval_route_result['status'], 'adapter does not publish standalone approval route' );
maa_adapter_smoke_assert( 'rest_no_route' === (string) ( $approval_route_result['data']['code'] ?? '' ), 'adapter standalone approval route is absent' );

$after_approval_route_check = maa_adapter_smoke_rest( 'GET', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $proposal_id ) );
maa_adapter_smoke_assert( 'pending' === (string) ( $after_approval_route_check['status'] ?? '' ), 'absent standalone approval route does not change Core proposal status' );

$rejection_route_result = maa_adapter_smoke_rest_result( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $proposal_id ) . '/reject' );
maa_adapter_smoke_assert( 404 === (int) $rejection_route_result['status'], 'adapter does not publish standalone rejection route' );
maa_adapter_smoke_assert( 'rest_no_route' === (string) ( $rejection_route_result['data']['code'] ?? '' ), 'adapter standalone rejection route is absent' );

$after_rejection_route_check = maa_adapter_smoke_rest( 'GET', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $proposal_id ) );
maa_adapter_smoke_assert( 'pending' === (string) ( $after_rejection_route_check['status'] ?? '' ), 'absent standalone rejection route does not change Core proposal status' );

$approved = maa_adapter_smoke_rest(
	'POST',
	'/npcink-governance-core/v1/proposals/' . rawurlencode( $proposal_id ) . '/approve',
	array(
		'note' => 'Adapter provider log correlation smoke approval.',
	)
);
maa_adapter_smoke_assert( 'approved' === (string) ( $approved['status'] ?? '' ), 'Core admin REST approval succeeds for provider log correlation smoke' );

$preflight = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $proposal_id ) . '/commit-preflight' );
$correlation_id = (string) ( $preflight['correlation_id'] ?? '' );
maa_adapter_smoke_assert( '' !== $correlation_id, 'adapter commit preflight returns correlation id for provider log smoke' );
maa_adapter_smoke_assert( false === (bool) ( $preflight['commit_execution'] ?? true ), 'adapter commit preflight keeps final execution disabled for provider log smoke' );
maa_adapter_smoke_assert( $correlation_id === (string) ( $preflight['approval_context']['correlation_id'] ?? '' ), 'adapter commit preflight approval context carries matching correlation id' );
$commit_preflight_event = maa_adapter_smoke_observability_event( 'adapter.commit.preflight', 'ok', '/npcink-openclaw-adapter/v1/proposals/' . $proposal_id . '/commit-preflight' );
maa_adapter_smoke_assert( ! empty( $commit_preflight_event ), 'adapter emits commit preflight success observability event' );
maa_adapter_smoke_assert( $proposal_id === (string) ( $commit_preflight_event['proposal_id'] ?? '' ), 'adapter commit preflight success event carries proposal id' );
maa_adapter_smoke_assert_observability_safe( $commit_preflight_event, 'adapter commit preflight success event' );

$missing_preflight = maa_adapter_smoke_rest_result( 'POST', '/npcink-openclaw-adapter/v1/proposals/missing-observability-smoke/commit-preflight' );
maa_adapter_smoke_assert( 404 === (int) $missing_preflight['status'], 'adapter commit preflight failure smoke returns missing proposal status' );
$commit_preflight_error_event = maa_adapter_smoke_observability_event( 'adapter.commit.preflight', 'error', '/npcink-openclaw-adapter/v1/proposals/missing-observability-smoke/commit-preflight' );
maa_adapter_smoke_assert( ! empty( $commit_preflight_error_event ), 'adapter emits commit preflight failure observability event' );
maa_adapter_smoke_assert( 404 === (int) ( $commit_preflight_error_event['status_code'] ?? 0 ), 'adapter commit preflight failure event carries status code' );
maa_adapter_smoke_assert( '' !== (string) ( $commit_preflight_error_event['error_code'] ?? '' ), 'adapter commit preflight failure event carries stable error code' );
maa_adapter_smoke_assert_observability_safe( $commit_preflight_error_event, 'adapter commit preflight failure event' );

$adapter_core_app = maa_adapter_smoke_rest(
	'POST',
	'/npcink-governance-core/v1/apps',
	array(
		'app_label'           => 'Adapter app-token smoke',
		'caller_type'         => 'openclaw_adapter',
		'scopes'              => array( 'proposals:create', 'proposals:read', 'commit:preflight' ),
		'rate_limit'          => 20,
		'rate_window_seconds' => 3600,
	)
);
$adapter_core_app_token = (string) ( $adapter_core_app['token'] ?? '' );
$adapter_core_app_id    = (string) ( $adapter_core_app['app_id'] ?? '' );
$adapter_core_key_id    = (string) ( $adapter_core_app['key_id'] ?? '' );
$maa_adapter_smoke_fixture_registry['core_app_ids'][] = $adapter_core_app_id;
$maa_adapter_smoke_fixture_registry['core_app_key_ids'][] = $adapter_core_key_id;
maa_adapter_smoke_assert( '' !== $adapter_core_app_token && '' !== $adapter_core_app_id && '' !== $adapter_core_key_id, 'adapter smoke created scoped Core app token' );
maa_adapter_smoke_assert( in_array( 'proposals:create', (array) ( $adapter_core_app['scopes'] ?? array() ), true ), 'adapter Core app token includes proposal creation scope' );
maa_adapter_smoke_assert( in_array( 'proposals:read', (array) ( $adapter_core_app['scopes'] ?? array() ), true ), 'adapter Core app token includes proposal read scope' );
maa_adapter_smoke_assert( in_array( 'commit:preflight', (array) ( $adapter_core_app['scopes'] ?? array() ), true ), 'adapter Core app token includes commit preflight scope' );
maa_adapter_smoke_assert( ! in_array( 'proposals:approve', (array) ( $adapter_core_app['scopes'] ?? array() ), true ), 'adapter Core app token does not include approval scope' );
maa_adapter_smoke_assert( ! in_array( 'audit:read', (array) ( $adapter_core_app['scopes'] ?? array() ), true ), 'adapter Core app token does not include audit read scope' );

$previous_adapter_core_app_token = get_option( 'npcink_openclaw_adapter_core_app_token', null );
update_option( 'npcink_openclaw_adapter_core_app_token', $adapter_core_app_token, false );

$app_token_health = maa_adapter_smoke_rest( 'GET', '/npcink-openclaw-adapter/v1/health' );
maa_adapter_smoke_assert( true === (bool) ( $app_token_health['core_app_token_configured'] ?? false ), 'adapter health reports Core app token configured' );
maa_adapter_smoke_assert_payload_excludes_string( $app_token_health, $adapter_core_app_token, 'adapter health with Core app token' );
$app_token_help = maa_adapter_smoke_rest( 'GET', '/npcink-openclaw-adapter/v1/help' );
maa_adapter_smoke_assert( true === (bool) ( $app_token_help['core_app_token_configured'] ?? false ), 'adapter help reports Core app token configured' );
maa_adapter_smoke_assert_payload_excludes_string( $app_token_help, $adapter_core_app_token, 'adapter help with Core app token' );

$app_token_proposal = maa_adapter_smoke_rest(
	'POST',
	'/npcink-openclaw-adapter/v1/proposals',
	array(
		'ability_id' => 'npcink-abilities-toolkit/create-draft',
		'title'      => 'Adapter app token proposal smoke',
		'summary'    => 'Adapter must route proposal create to Core with app attribution.',
		'input'      => array(
			'title'   => 'Adapter app token proposal smoke',
			'dry_run' => true,
			'commit'  => false,
		),
		'preview'    => array(
			'mode' => 'adapter_app_token_smoke',
		),
		'caller'     => array(
			'external_thread_id' => 'adapter-app-token-smoke',
		),
	)
);
$app_token_proposal_id = (string) ( $app_token_proposal['proposal_id'] ?? '' );
$maa_adapter_smoke_cleanup_proposal_ids[] = $app_token_proposal_id;
maa_adapter_smoke_assert( '' !== $app_token_proposal_id, 'adapter creates proposal through Core app token' );
maa_adapter_smoke_assert( $adapter_core_app_id === (string) ( $app_token_proposal['caller']['auth']['app_id'] ?? '' ), 'adapter app-token proposal stores app attribution' );
maa_adapter_smoke_assert( 'proposals:create' === (string) ( $app_token_proposal['caller']['auth']['scope'] ?? '' ), 'adapter app-token proposal stores create scope attribution' );
maa_adapter_smoke_assert_payload_excludes_string( $app_token_proposal, $adapter_core_app_token, 'adapter app-token proposal create response' );

$app_token_detail = maa_adapter_smoke_rest( 'GET', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $app_token_proposal_id ) );
maa_adapter_smoke_assert( $app_token_proposal_id === (string) ( $app_token_detail['proposal_id'] ?? '' ), 'adapter reads app-token proposal status through Core app token' );
maa_adapter_smoke_assert_payload_excludes_string( $app_token_detail, $adapter_core_app_token, 'adapter app-token proposal detail response' );

$app_token_approved = maa_adapter_smoke_rest(
	'POST',
	'/npcink-governance-core/v1/proposals/' . rawurlencode( $app_token_proposal_id ) . '/approve',
	array(
		'note' => 'Admin approval for Adapter app token smoke.',
	)
);
maa_adapter_smoke_assert( 'approved' === (string) ( $app_token_approved['status'] ?? '' ), 'Core admin approval succeeds for Adapter app-token proposal' );

$app_token_preflight = maa_adapter_smoke_rest( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . rawurlencode( $app_token_proposal_id ) . '/commit-preflight' );
maa_adapter_smoke_assert( true === (bool) ( $app_token_preflight['approval_context']['approval_commit_authorized'] ?? false ), 'adapter app-token commit preflight returns authorized approval context' );
maa_adapter_smoke_assert( 'core-preflight-v1' === (string) ( $app_token_preflight['approval_context']['policy_version'] ?? '' ), 'adapter app-token commit preflight returns policy version' );
maa_adapter_smoke_assert( hash( 'sha256', (string) wp_json_encode( $app_token_detail['input'] ?? array() ) ) === (string) ( $app_token_preflight['approval_context']['approved_input_hash'] ?? '' ), 'adapter app-token commit preflight hash matches proposal input' );
maa_adapter_smoke_assert_payload_excludes_string( $app_token_preflight, $adapter_core_app_token, 'adapter app-token commit preflight response' );

$app_token_error = maa_adapter_smoke_rest_result( 'GET', '/npcink-openclaw-adapter/v1/proposals/missing-app-token-smoke' );
maa_adapter_smoke_assert( 404 === (int) $app_token_error['status'], 'adapter app-token missing proposal returns error status' );
maa_adapter_smoke_assert_payload_excludes_string( $app_token_error, $adapter_core_app_token, 'adapter app-token error response' );
$app_token_create_event = maa_adapter_smoke_observability_event( 'adapter.proposal.create', 'ok' );
maa_adapter_smoke_assert_payload_excludes_string( $app_token_create_event, $adapter_core_app_token, 'adapter app-token proposal create log event' );
$app_token_preflight_event = maa_adapter_smoke_observability_event( 'adapter.commit.preflight', 'ok', '/npcink-openclaw-adapter/v1/proposals/' . $app_token_proposal_id . '/commit-preflight' );
maa_adapter_smoke_assert_payload_excludes_string( $app_token_preflight_event, $adapter_core_app_token, 'adapter app-token preflight log event' );

$app_token_audit = maa_adapter_smoke_rest(
	'GET',
	'/npcink-governance-core/v1/audit',
	array(
		'proposal_id' => $app_token_proposal_id,
		'limit'       => 20,
	)
);
$found_app_token_create_audit   = false;
$found_app_token_preflight_audit = false;
foreach ( (array) ( $app_token_audit['items'] ?? array() ) as $audit_item ) {
	if ( ! is_array( $audit_item ) ) {
		continue;
	}
	$auth = is_array( $audit_item['metadata']['auth'] ?? null ) ? $audit_item['metadata']['auth'] : array();
	if ( 'proposal.created' === (string) ( $audit_item['event_name'] ?? '' ) ) {
		$found_app_token_create_audit = $adapter_core_app_id === (string) ( $auth['app_id'] ?? '' ) && 'proposals:create' === (string) ( $auth['scope'] ?? '' );
	}
	if ( 'commit.preflighted' === (string) ( $audit_item['event_name'] ?? '' ) ) {
		$found_app_token_preflight_audit = $adapter_core_app_id === (string) ( $auth['app_id'] ?? '' ) && 'commit:preflight' === (string) ( $auth['scope'] ?? '' );
	}
}
maa_adapter_smoke_assert( $found_app_token_create_audit, 'Core audit stores Adapter app attribution for proposal creation' );
maa_adapter_smoke_assert( $found_app_token_preflight_audit, 'Core audit stores Adapter app attribution for commit preflight' );

if ( null === $previous_adapter_core_app_token ) {
	delete_option( 'npcink_openclaw_adapter_core_app_token' );
} else {
	update_option( 'npcink_openclaw_adapter_core_app_token', $previous_adapter_core_app_token, false );
}

$provider_smoke = maa_adapter_smoke_rest_result(
	'POST',
	'/npcink-openclaw-adapter/v1/ai-provider-log-correlation-smoke',
	array(
		'proposal_id'    => $proposal_id,
		'correlation_id' => $correlation_id,
		'ability_id'     => 'npcink-abilities-toolkit/create-draft',
		'prompt'         => 'Reply with exactly: OK',
	)
);
maa_adapter_smoke_assert( 404 === (int) $provider_smoke['status'], 'adapter provider smoke route is removed from the thin channel surface' );

$core_correlation_audit = maa_adapter_smoke_rest(
	'GET',
	'/npcink-governance-core/v1/audit',
	array(
		'correlation_id' => $correlation_id,
		'limit'          => 10,
	)
);
maa_adapter_smoke_assert( is_array( $core_correlation_audit['items'] ?? null ), 'Core Governance Audit remains available after provider smoke route removal' );

$maa_adapter_smoke_cleanup_proposal_ids[] = $proposal_id;
maa_adapter_smoke_export_visual_acceptance_fixtures();
maa_adapter_smoke_cleanup_registered_fixtures();
maa_adapter_smoke_assert( true, 'adapter status smoke cleaned created proposal records' );
maa_adapter_smoke_assert_no_media_fixture_leaks();

echo "npcink-openclaw-adapter WordPress smoke: ok\n";
