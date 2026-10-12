<?php
/**
 * Remove Adapter-owned local bridge state on uninstall.
 *
 * @package NpcinkOpenClawAdapter
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Revokes WordPress Application Passwords created through the Adapter fallback.
 *
 * Each created credential lives in its own uniquely keyed
 * `npcink_openclaw_adapter_fallback_app_password_*` option row, so enumeration
 * cannot miss entries and concurrent creations cannot race. Only credentials
 * created by this plugin's fallback flow are revoked; other Application
 * Passwords on the site are untouched.
 *
 * @return void
 */
function npcink_openclaw_adapter_revoke_fallback_app_passwords(): void {
	global $wpdb;

	if ( ! class_exists( 'WP_Application_Passwords' ) ) {
		return;
	}

	$like = $wpdb->esc_like( 'npcink_openclaw_adapter_fallback_app_password_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall enumerates the adapter-owned credential rows before the prefix wipe deletes them.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
			$like
		),
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return;
	}

	foreach ( $rows as $row ) {
		$entry = isset( $row['option_value'] ) ? maybe_unserialize( $row['option_value'] ) : null;
		if ( ! is_array( $entry ) ) {
			continue;
		}

		$user_id = isset( $entry['user_id'] ) ? absint( $entry['user_id'] ) : 0;
		$uuid    = isset( $entry['uuid'] ) ? sanitize_text_field( (string) $entry['uuid'] ) : '';
		if ( 0 === $user_id || '' === $uuid ) {
			continue;
		}

		// Already-deleted passwords and removed users fail silently here; the
		// plugin is being uninstalled, so nothing depends on the outcome.
		\WP_Application_Passwords::delete_application_password( $user_id, $uuid );
	}
}

/**
 * Deletes Adapter-owned options and transient rows for the current site.
 *
 * Core proposal, approval, preflight, and audit records are intentionally not
 * touched because they belong to Npcink Governance Core.
 *
 * @return void
 */
function npcink_openclaw_adapter_uninstall_current_site(): void {
	global $wpdb;

	npcink_openclaw_adapter_revoke_fallback_app_passwords();

	foreach (
		array(
			'npcink_openclaw_adapter_device_pairings',
			'npcink_openclaw_adapter_client_keys',
			'npcink_openclaw_adapter_execution_records',
			'npcink_openclaw_adapter_preflight_handoffs',
		) as $option_name
	) {
		delete_option( $option_name );
	}

	$like = $wpdb->esc_like( 'npcink_openclaw_adapter_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must remove adapter-owned dynamic options atomically.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$like
		)
	);

	$transient_like = $wpdb->esc_like( '_transient_npcink_openclaw_adapter_' ) . '%';
	$timeout_like   = $wpdb->esc_like( '_transient_timeout_npcink_openclaw_adapter_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must remove adapter-owned transients that have no stable API.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$transient_like,
			$timeout_like
		)
	);
}

if ( is_multisite() ) {
	$npcink_openclaw_adapter_site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $npcink_openclaw_adapter_site_ids as $npcink_openclaw_adapter_site_id ) {
		switch_to_blog( (int) $npcink_openclaw_adapter_site_id );
		npcink_openclaw_adapter_uninstall_current_site();
		restore_current_blog();
	}
} else {
	npcink_openclaw_adapter_uninstall_current_site();
}
