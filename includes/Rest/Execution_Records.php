<?php
/**
 * Adapter-owned execution record and lock storage domain service.
 *
 * Pure bridge-state ownership: bounded execution records, idempotency keys,
 * execution locks, and their public/verification projections. No upstream
 * dispatch, no proposal truth (Core owns approval and audit), no route
 * surface; write-path orchestrators stay in the Controller.
 *
 * @package NpcinkOpenClawAdapter
 */

namespace Npcink\OpenClawAdapter\Rest;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores bounded execution records and execution locks.
 */
final class Execution_Records {

	/**
	 * Builds the storage key for an execution record.
	 *
	 * @param string $proposal_id Proposal id.
	 * @return string
	 */
	public function record_key( string $proposal_id ): string {
		return md5( $proposal_id );
	}
	/**
	 * Returns stored execution records.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function records(): array {
		$records = get_option( Controller::EXECUTION_RECORDS_OPTION, array() );
		return is_array( $records ) ? $records : array();
	}
	/**
	 * Removes old execution records after the bounded retention limit.
	 *
	 * @param array<string,array<string,mixed>> $records Records.
	 * @return array<string,array<string,mixed>>
	 */
	public function prune( array $records ): array {
		$oldest = time() - Controller::EXECUTION_RECORD_RETENTION_TTL;
		foreach ( $records as $key => $record ) {
			$executed_at = is_array( $record ) ? strtotime( (string) ( $record['executed_at'] ?? ( $record['failed_at'] ?? '' ) ) ) : false;
			if ( false === $executed_at || $executed_at < $oldest ) {
				unset( $records[ $key ] );
			}
		}

		if ( count( $records ) <= Controller::MAX_EXECUTION_RECORDS ) {
			return $records;
		}

		uasort(
			$records,
			static function ( $left, $right ): int {
				$left_time  = is_array( $left ) ? (string) ( $left['executed_at'] ?? ( $left['failed_at'] ?? '' ) ) : '';
				$right_time = is_array( $right ) ? (string) ( $right['executed_at'] ?? ( $right['failed_at'] ?? '' ) ) : '';

				return strcmp( $left_time, $right_time );
			}
		);

		return array_slice( $records, - Controller::MAX_EXECUTION_RECORDS, null, true );
	}
	/**
	 * Returns any stored execution record for a proposal.
	 *
	 * @param string $proposal_id Proposal id.
	 * @return array<string,mixed>|null
	 */
	public function record_for_proposal( string $proposal_id ): ?array {
		$records = $this->records();
		$key     = $this->record_key( $proposal_id );
		$record  = is_array( $records[ $key ] ?? null ) ? $records[ $key ] : array();

		return empty( $record ) ? null : $record;
	}
	/**
	 * Returns the completed execution record for a proposal.
	 *
	 * @param string $proposal_id Proposal id.
	 * @return array<string,mixed>|null
	 */
	public function completed_record( string $proposal_id ): ?array {
		$record = $this->record_for_proposal( $proposal_id );

		if ( empty( $record ) || 'succeeded' !== (string) ( $record['status'] ?? '' ) ) {
			return null;
		}

		return $record;
	}
	/**
	 * Builds an error for a duplicate execution attempt.
	 *
	 * @param string              $proposal_id Proposal id.
	 * @param array<string,mixed> $record Completed execution record.
	 * @return WP_Error
	 */
	public function already_completed_error( string $proposal_id, array $record ): WP_Error {
		return new WP_Error(
			'npcink_openclaw_adapter_execution_already_completed',
			__( 'Adapter has already completed execution for this proposal.', 'npcink-ai-client-adapter' ),
			array(
				'status'              => 409,
				'proposal_id'         => $proposal_id,
				'ability_id'          => (string) ( $record['ability_id'] ?? '' ),
				'approved_input_hash' => (string) ( $record['approved_input_hash'] ?? '' ),
				'correlation_id'      => (string) ( $record['correlation_id'] ?? '' ),
				'adapter_request_id'  => (string) ( $record['adapter_request_id'] ?? '' ),
				'commit_execution'    => false,
				'execution_record'    => $this->public_record( $record ),
			)
		);
	}
	/**
	 * Returns a public-safe execution record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return array<string,mixed>
	 */
	public function public_record( array $record ): array {
		return array(
			'status'                          => (string) ( $record['status'] ?? '' ),
			'proposal_id'                     => (string) ( $record['proposal_id'] ?? '' ),
			'ability_id'                      => (string) ( $record['ability_id'] ?? '' ),
			'proposal_ability_id'             => (string) ( $record['proposal_ability_id'] ?? '' ),
			'approved_input_hash'             => (string) ( $record['approved_input_hash'] ?? '' ),
			'correlation_id'                  => (string) ( $record['correlation_id'] ?? '' ),
			'adapter_request_id'              => (string) ( $record['adapter_request_id'] ?? '' ),
			'execution_mode'                  => (string) ( $record['execution_mode'] ?? '' ),
			'execution_surface'               => (string) ( $record['execution_surface'] ?? '' ),
			'execution_handoff_posture'       => is_array( $record['execution_handoff_posture'] ?? null ) ? $record['execution_handoff_posture'] : Contract_Metadata::execution_handoff_posture(),
			'commit_execution'                => (bool) ( $record['commit_execution'] ?? false ),
			'post_id'                         => absint( $record['post_id'] ?? 0 ),
			'post_ids'                        => array_values( array_map( 'absint', is_array( $record['post_ids'] ?? null ) ? $record['post_ids'] : array() ) ),
			'selected_count'                  => absint( $record['selected_count'] ?? ( $record['executed_count'] ?? 0 ) ),
			'submitted_count'                 => absint( $record['submitted_count'] ?? ( $record['executed_count'] ?? 0 ) ),
			'executed_count'                  => absint( $record['executed_count'] ?? 0 ),
			'failed_count'                    => absint( $record['failed_count'] ?? 0 ),
			'blocked_count'                   => absint( $record['blocked_count'] ?? 0 ),
			'partial_success'                 => (bool) ( $record['partial_success'] ?? false ),
			'retryable'                       => (bool) ( $record['retryable'] ?? false ),
			'operator_next_action'            => (string) ( $record['operator_next_action'] ?? '' ),
			'error_code'                      => (string) ( $record['error_code'] ?? '' ),
			'failed_action_id'                => (string) ( $record['failed_action_id'] ?? '' ),
			'failed_action_index'             => absint( $record['failed_action_index'] ?? 0 ),
			'failed_execution_profile'        => (string) ( $record['failed_execution_profile'] ?? '' ),
			'failed_idempotency_key'          => (string) ( $record['failed_idempotency_key'] ?? '' ),
			'core_preflight_evidence'         => is_array( $record['core_preflight_evidence'] ?? null ) ? $record['core_preflight_evidence'] : null,
			'implementation_posture_evidence' => is_array( $record['implementation_posture_evidence'] ?? null ) ? $record['implementation_posture_evidence'] : null,
			'media_alt_live_preflight'        => is_array( $record['media_alt_live_preflight'] ?? null ) ? $record['media_alt_live_preflight'] : null,
			'verification'                    => is_array( $record['verification'] ?? null ) ? $record['verification'] : null,
			'core_execution_record'           => is_array( $record['core_execution_record'] ?? null ) ? $record['core_execution_record'] : null,
			'failed_at'                       => (string) ( $record['failed_at'] ?? '' ),
			'executed_at'                     => (string) ( $record['executed_at'] ?? '' ),
		);
	}
	/**
	 * Extracts public-safe verification summaries from ability execution output.
	 *
	 * @param array<string,mixed> $execution Execution result.
	 * @return array<string,mixed>|null
	 */
	public function compact_verification( array $execution ): ?array {
		$items = array();
		foreach ( (array) ( $execution['results'] ?? array() ) as $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}
			$ability_result = is_array( $result['result'] ?? null ) ? $result['result'] : array();
			$verification   = is_array( $ability_result['verification'] ?? null ) ? $ability_result['verification'] : array();
			if ( empty( $verification ) ) {
				continue;
			}
			$items[] = array(
				'action_id'         => sanitize_key( (string) ( $result['action_id'] ?? '' ) ),
				'action_index'      => absint( $result['action_index'] ?? 0 ),
				'target_ability_id' => sanitize_text_field( (string) ( $result['target_ability_id'] ?? ( $result['ability_id'] ?? '' ) ) ),
				'verification'      => $this->sanitize_verification_summary( $verification ),
			);
		}

		if ( empty( $items ) ) {
			return null;
		}

		return array(
			'status'     => 'recorded',
			'item_count' => count( $items ),
			'items'      => $items,
			'aggregates' => $this->aggregate_verification( $items ),
		);
	}
	/**
	 * Sanitizes an ability verification summary for Adapter execution records.
	 *
	 * @param array<string,mixed> $verification Ability verification payload.
	 * @return array<string,mixed>
	 */
	public function sanitize_verification_summary( array $verification ): array {
		$allowed = array(
			'media_current_file',
			'media_mime_type',
			'post_references_verified',
			'content_reference_post_count',
			'content_reference_actual_replacement_count',
			'content_reference_unmatched_rules',
			'block_readback_status',
			'block_readback_ability_id',
			'block_readback_post_id',
			'block_readback_post_type',
			'block_readback_slug',
			'block_readback_block_count',
			'block_readback_content_length',
			'block_readback_error_code',
			'block_readback_status_code',
			'block_write_block_count_after',
			'block_write_validation_valid',
			'block_write_roundtrip_checked',
			'block_write_roundtrip_ok',
			'backup_available',
			'rollback_available',
		);
		$output  = array();
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $verification ) ) {
				continue;
			}
			$value = $verification[ $key ];
			if ( is_bool( $value ) ) {
				$output[ $key ] = $value;
			} elseif ( is_int( $value ) || is_float( $value ) ) {
				$output[ $key ] = $value;
			} elseif ( is_array( $value ) ) {
				$output[ $key ] = $this->sanitize_verification_array( $value );
			} else {
				$output[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return $output;
	}
	/**
	 * Sanitizes nested verification arrays.
	 *
	 * @param array<mixed> $value Value.
	 * @return array<mixed>
	 */
	public function sanitize_verification_array( array $value ): array {
		$output = array();
		foreach ( $value as $key => $item ) {
			$output_key = is_int( $key ) ? $key : sanitize_key( (string) $key );
			if ( is_array( $item ) ) {
				$output[ $output_key ] = $this->sanitize_verification_array( $item );
			} elseif ( is_bool( $item ) || is_int( $item ) || is_float( $item ) ) {
				$output[ $output_key ] = $item;
			} else {
				$output[ $output_key ] = sanitize_text_field( (string) $item );
			}
		}

		return $output;
	}
	/**
	 * Builds cross-action verification aggregates.
	 *
	 * @param array<int,array<string,mixed>> $items Verification items.
	 * @return array<string,mixed>
	 */
	public function aggregate_verification( array $items ): array {
		$backup_available        = false;
		$rollback_available      = false;
		$actual_replacements     = 0;
		$post_ids                = array();
		$has_post_references     = false;
		$old_urls_absent         = true;
		$new_urls_present        = true;
		$block_readbacks         = 0;
		$block_readback_failures = 0;

		foreach ( $items as $item ) {
			$verification         = is_array( $item['verification'] ?? null ) ? $item['verification'] : array();
			$backup_available     = $backup_available || (bool) ( $verification['backup_available'] ?? false );
			$rollback_available   = $rollback_available || (bool) ( $verification['rollback_available'] ?? false );
			$actual_replacements += absint( $verification['content_reference_actual_replacement_count'] ?? 0 );
			if ( 'verified' === (string) ( $verification['block_readback_status'] ?? '' ) ) {
				++$block_readbacks;
			} elseif ( 'readback_failed' === (string) ( $verification['block_readback_status'] ?? '' ) ) {
				++$block_readback_failures;
			}
			foreach ( (array) ( $verification['post_references_verified'] ?? array() ) as $post_reference ) {
				if ( is_array( $post_reference ) ) {
					$post_ids[]          = absint( $post_reference['post_id'] ?? 0 );
					$has_post_references = true;
					$old_urls_absent     = $old_urls_absent && (bool) ( $post_reference['old_url_absent'] ?? false );
					$new_urls_present    = $new_urls_present && (bool) ( $post_reference['new_url_present'] ?? false );
				} else {
					$post_ids[] = absint( $post_reference );
				}
			}
		}

		return array(
			'backup_available'                           => $backup_available,
			'rollback_available'                         => $rollback_available,
			'content_reference_actual_replacement_count' => $actual_replacements,
			'post_references_verified'                   => array_values( array_unique( array_filter( $post_ids ) ) ),
			'post_reference_count'                       => count( array_unique( array_filter( $post_ids ) ) ),
			'post_reference_old_urls_absent'             => $has_post_references ? $old_urls_absent : null,
			'post_reference_new_urls_present'            => $has_post_references ? $new_urls_present : null,
			'block_readback_verified_count'              => $block_readbacks,
			'block_readback_failed_count'                => $block_readback_failures,
		);
	}
	/**
	 * Acquires a short per-proposal execution lock.
	 *
	 * The lock value carries a unique token: release_lock() deletes the row
	 * only while the stored token still matches, so a holder whose execution
	 * outlived the TTL cannot delete a newer holder's live lock. Atomicity
	 * follows the Signing_Auth nonce-claim pattern: INSERT IGNORE is the
	 * strict fresh-row claim (WordPress 7 add_option() degrades to
	 * ON DUPLICATE KEY UPDATE), and an expired row is taken over by a
	 * conditional UPDATE that only lands while the stored value is still
	 * the expired row that was read, so at most one concurrent takeover
	 * can win.
	 *
	 * @param string $proposal_id Proposal id.
	 * @return array{0:string,1:string}|WP_Error Array of lock option key and unique lock token, or lock contention error.
	 */
	public function acquire_lock( string $proposal_id ) {
		global $wpdb;

		$key  = 'npcink_openclaw_adapter_exec_lock_' . md5( $proposal_id );
		$now  = time();
		$lock = array(
			'proposal_id' => $proposal_id,
			'token'       => wp_generate_password( 32, false, false ),
			'acquired_at' => gmdate( 'c', $now ),
			'expires_at'  => $now + Controller::EXECUTION_LOCK_TTL,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT IGNORE is the atomic lock claim primitive.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$key,
				maybe_serialize( $lock ),
				'off'
			)
		);
		if ( 1 === (int) $inserted ) {
			return array( $key, $lock['token'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw read bypasses the options cache to preserve lock claim semantics.
		$raw      = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$key
			)
		);
		$existing = is_string( $raw ) ? maybe_unserialize( $raw ) : null;
		if ( ! is_array( $existing ) || $now < (int) ( $existing['expires_at'] ?? 0 ) ) {
			return new WP_Error(
				'npcink_openclaw_adapter_execution_in_progress',
				__( 'This proposal is already being executed. Try again shortly.', 'npcink-ai-client-adapter' ),
				array(
					'status'      => 409,
					'proposal_id' => $proposal_id,
					'retry_after' => Controller::EXECUTION_LOCK_TTL,
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional update is the compare-and-swap takeover of the expired row.
		$took_over = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $lock ),
				$key,
				$raw
			)
		);
		if ( 1 === (int) $took_over ) {
			return array( $key, $lock['token'] );
		}

		return new WP_Error(
			'npcink_openclaw_adapter_execution_in_progress',
			__( 'This proposal is already being executed. Try again shortly.', 'npcink-ai-client-adapter' ),
			array(
				'status'      => 409,
				'proposal_id' => $proposal_id,
				'retry_after' => Controller::EXECUTION_LOCK_TTL,
			)
		);
	}
	/**
	 * Releases a per-proposal execution lock.
	 *
	 * Deletes the row only while the stored token still matches this
	 * holder's acquisition token, and only while the stored value is
	 * unchanged since it was read, so neither a newer holder's live lock
	 * nor a takeover landing mid-release can be removed.
	 *
	 * @param string $lock_key Lock option key.
	 * @param string $lock_token Unique lock token returned by acquire_lock().
	 * @return void
	 */
	public function release_lock( string $lock_key, string $lock_token ): void {
		global $wpdb;

		if ( '' === $lock_key ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw read bypasses the options cache to preserve lock release semantics.
		$raw    = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$lock_key
			)
		);
		$stored = is_string( $raw ) ? maybe_unserialize( $raw ) : null;
		if ( ! is_array( $stored ) || ! hash_equals( $lock_token, (string) ( $stored['token'] ?? '' ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional delete removes only this holder's unchanged row.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$lock_key,
				$raw
			)
		);
	}
}
