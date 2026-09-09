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
 * Deletes Adapter-owned options and transient rows for the current site.
 *
 * Core proposal, approval, preflight, and audit records are intentionally not
 * touched because they belong to Npcink Governance Core.
 *
 * @return void
 */
function npcink_openclaw_adapter_uninstall_current_site(): void {
	global $wpdb;

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
