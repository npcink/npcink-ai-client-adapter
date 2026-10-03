<?php
/**
 * Local observability event bridge.
 *
 * @package NpcinkOpenClawAdapter
 */

namespace Npcink\OpenClawAdapter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits metadata-only events for optional Cloud Addon collection.
 */
final class Observability {
	/**
	 * Emits a local-only observability event.
	 *
	 * @param string              $event_kind Event kind.
	 * @param array<string,mixed> $payload Event details.
	 * @return void
	 */
	public static function emit( string $event_kind, array $payload = array() ): void {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		do_action(
			'npcink_openclaw_adapter_observability_event',
			array_merge(
				self::sanitize_payload( $payload ),
				array(
					'schema_version' => '2026-06-01',
					'plugin_slug'    => 'npcink-ai-client-adapter',
					'plugin_version' => defined( 'NPCINK_OPENCLAW_ADAPTER_VERSION' ) ? NPCINK_OPENCLAW_ADAPTER_VERSION : '',
					'source'         => 'local',
					'event_kind'     => $event_kind,
					'emitted_at'     => gmdate( 'c' ),
				)
			)
		);
	}

	/**
	 * Keeps observability metadata bounded and safe for optional collection.
	 *
	 * @param array<string,mixed> $payload Event details.
	 * @return array<string,mixed>
	 */
	private static function sanitize_payload( array $payload ): array {
		$allowed = array(
			'event_id'           => 'key',
			'status'             => 'status',
			'error_code'         => 'key',
			'status_detail'      => 'key',
			'latency_ms'         => 'int',
			'ability_id'         => 'text',
			'proposal_id'        => 'text',
			'correlation_id'     => 'text',
			'adapter_request_id' => 'text',
			'method'             => 'method',
			'route'              => 'text',
			'status_code'        => 'int',
			'mode'               => 'key',
			'deduplicated'       => 'bool',
			'proposal_count'     => 'int',
			'blocked_count'      => 'int',
			'executed_count'     => 'int',
			'failed_count'       => 'int',
			'read_policy'        => 'key',
			'sensitivity'        => 'key',
		);
		$clean   = array();

		foreach ( $allowed as $key => $type ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				continue;
			}

			$value = $payload[ $key ];
			if ( 'int' === $type ) {
				$clean[ $key ] = max( 0, (int) $value );
				continue;
			}

			if ( 'bool' === $type ) {
				$clean[ $key ] = (bool) $value;
				continue;
			}

			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}

			$value = substr( sanitize_text_field( (string) $value ), 0, 200 );
			if ( 'status' === $type && ! in_array( $value, array( 'ok', 'warning', 'error' ), true ) ) {
				$value = 'error';
			} elseif ( 'method' === $type ) {
				$value = strtoupper( sanitize_key( $value ) );
			} elseif ( 'key' === $type ) {
				$value = sanitize_key( $value );
			}

			if ( '' !== $value ) {
				$clean[ $key ] = $value;
			}
		}

		return $clean;
	}
}
