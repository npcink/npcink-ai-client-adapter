<?php
/**
 * WP-CLI fixture setup, administrator approval and readback for HTTPS acceptance.
 * This helper never dispatches an Adapter final write.
 */

use Npcink_Abilities_Toolkit\Support\Flagged_Media_Backup as Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
$state_file = (string) getenv( 'MAA_FLAGGED_FIXTURE_STATE' );
$action = (string) getenv( 'MAA_FLAGGED_FIXTURE_ACTION' );
$branch = (string) getenv( 'MAA_FLAGGED_FIXTURE_BRANCH' );
$state = is_file( $state_file ) ? json_decode( file_get_contents( $state_file ), true ) : array();
$check = static function ( $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$save = static function () use ( &$state, $state_file, $check ): void {
	$check( false !== file_put_contents( $state_file, wp_json_encode( $state ), LOCK_EX ), 'Could not persist fixture cleanup state.' );
};
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$check( ! empty( $admins ), 'An administrator test context is required.' );
wp_set_current_user( (int) $admins[0] );
$upload = wp_upload_dir();
$check( empty( $upload['error'] ), 'Uploads directory is unavailable.' );

if ( 'identity' === $action ) {
	echo wp_json_encode( array( 'site_url' => home_url() ) );
	return;
}

if ( 'prepare' === $action ) {
	$check( empty( $state ), 'Fixture state must be new.' );
	foreach ( array( 'build-flagged-media-removal-plan', 'delete-attachment', 'restore-media-backup' ) as $id ) {
		$check( (bool) wp_get_ability( 'npcink-abilities-toolkit/' . $id ), 'Required Toolkit ability unavailable: ' . $id );
	}
	$check( class_exists( Backup::class ), 'Toolkit backup implementation unavailable.' );
	$state = array( 'run' => 'adapter-flagged-https-' . bin2hex( random_bytes( 8 ) ), 'entries' => array() );
	$save();
	$dir = $upload['basedir'] . '/' . $state['run'];
	$check( ! file_exists( $dir ) && wp_mkdir_p( $dir ), 'Could not create unique fixture directory.' );
	$bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=' );
	foreach ( array( 'governed_with_backup', 'illegal_content_no_backup' ) as $name ) {
		$relative = $state['run'] . '/' . $name . '.png';
		$key = $state['run'] . '-' . $name;
		$state['entries'][ $name ] = array( 'relative' => $relative, 'attachment_id' => 0, 'sha256' => hash( 'sha256', $bytes ) );
		$save();
		$path = $upload['basedir'] . '/' . $relative;
		$check( false !== file_put_contents( $path, $bytes ), 'Could not write fixture image.' );
		$id = wp_insert_attachment( array( 'post_title' => $state['run'], 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $path, 0, true );
		$check( ! is_wp_error( $id ) && $id > 0, 'Could not create fixture attachment.' );
		$state['entries'][ $name ]['attachment_id'] = $id;
		$state['entries'][ $name ]['backup_id'] = Backup::id( $id, $key );
		$save();
		$check( ! isset( Backup::records()[ Backup::id( $id, $key ) ] ), 'Fixture backup id already exists.' );
		$input = array( 'attachment_id' => $id, 'deletion_policy_branch' => $name, 'reason' => 'Delete this disposable HTTPS acceptance fixture only', 'deletion_request_key' => $key, 'no_backup_confirmed' => 'illegal_content_no_backup' === $name );
		$plan = wp_get_ability( 'npcink-abilities-toolkit/build-flagged-media-removal-plan' )->execute( $input );
		$check( ! is_wp_error( $plan ) && isset( $plan['data']['write_actions'] ), 'Toolkit did not produce a removal plan.' );
		$body = array( 'plan_ability_id' => 'npcink-abilities-toolkit/build-flagged-media-removal-plan', 'plan' => $plan['data'], 'plan_input' => $input );
		$check( false !== file_put_contents( dirname( $state_file ) . '/' . $name . '.json', wp_json_encode( $body ) ), 'Could not save fixture plan.' );
	}
	echo "[flagged-https] two disposable attachment plans prepared\n";
	return;
}

$check( is_array( $state ) && preg_match( '/^adapter-flagged-https-[a-f0-9]{16}$/', $state['run'] ?? '' ), 'Invalid fixture cleanup state.' );
$entries = $state['entries'];
foreach ( $entries as $name => $entry ) {
	$check( in_array( $name, array( 'governed_with_backup', 'illegal_content_no_backup' ), true ) && $entry['relative'] === $state['run'] . '/' . $name . '.png', 'Fixture path does not match its run and branch.' );
	$check( is_int( $entry['attachment_id'] ) && $entry['attachment_id'] >= 0, 'Invalid fixture attachment identity.' );
	if ( $entry['attachment_id'] > 0 ) {
		$check( $entry['backup_id'] === Backup::id( $entry['attachment_id'], $state['run'] . '-' . $name ), 'Fixture backup identity does not match its run.' );
	}
}

if ( 'approve' === $action ) {
	$proposal_id = (string) getenv( 'MAA_FLAGGED_FIXTURE_PROPOSAL' );
	$check( isset( $entries[ $branch ] ), 'Approval requires a fixture branch.' );
	$entry = $entries[ $branch ];
	$read = rest_do_request( new WP_REST_Request( 'GET', '/npcink-governance-core/v1/proposals/' . rawurlencode( $proposal_id ) ) );
	$proposal = $read->get_data();
	$check( 200 === $read->get_status() && 'pending' === ( $proposal['status'] ?? '' ), 'Fixture approval requires a pending Core proposal.' );
	$input = $proposal['input'] ?? array();
	if ( 'npcink-abilities-toolkit/restore-media-backup' === ( $proposal['ability_id'] ?? '' ) ) {
		$check( (int) ( $input['attachment_id'] ?? 0 ) === $entry['attachment_id'] && ( $input['backup_id'] ?? '' ) === $entry['backup_id'], 'Restore approval target is outside this fixture.' );
	} else {
		$actions = $input['write_actions'] ?? array();
		$target = $actions[0]['input'] ?? array();
		$check( 1 === count( $actions ) && 'npcink-abilities-toolkit/delete-attachment' === ( $actions[0]['target_ability_id'] ?? '' ) && (int) ( $target['attachment_id'] ?? 0 ) === $entry['attachment_id'] && ( $target['deletion_policy_branch'] ?? '' ) === $branch, 'Delete approval target is outside this fixture.' );
	}
	$request = new WP_REST_Request( 'POST', '/npcink-governance-core/v1/proposals/' . rawurlencode( $proposal_id ) . '/approve' );
	$request->set_param( 'proposal_id', $proposal_id );
	$request->set_param( 'note', 'Administrator test-context approval of disposable HTTPS fixture.' );
	$request->set_param( 'no_backup_attestation_confirmed', true );
	$response = rest_do_request( $request );
	$data = $response->get_data();
	$check( 200 === $response->get_status() && 'approved' === ( $data['status'] ?? '' ), 'Core administrator test approval failed: ' . ( $data['code'] ?? '' ) );
	return;
}

if ( 'cleanup' === $action ) {
	$failures = array();
	foreach ( $entries as $entry ) {
		$relative = $entry['relative'];
		$check( 0 === strpos( $relative, $state['run'] . '/' ), 'Cleanup path is outside this run.' );
		// Match both the initial and restored attachment by the exact run-owned file.
		$posts = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'title' => $state['run'], 'numberposts' => 10 ) );
		foreach ( $posts as $post ) {
			if ( $relative === get_post_meta( $post->ID, '_wp_attached_file', true ) ) {
				wp_delete_attachment( $post->ID, true );
				if ( get_post( $post->ID ) ) { $failures[] = 'fixture attachment remains'; }
			}
		}
		$path = Backup::path( $relative );
		$check( '' !== $path, 'Fixture cleanup path could not be safely resolved.' );
		if ( is_file( $path ) && ! unlink( $path ) ) { $failures[] = 'fixture file remains'; }
	}
	$result = Backup::locked( static function () use ( $entries ) {
		$records = Backup::records();
		foreach ( $entries as $entry ) {
			$id = $entry['backup_id'] ?? '';
			if ( isset( $records[ $id ] ) ) {
				if ( (int) $records[ $id ]['attachment_id'] !== (int) $entry['attachment_id'] || ! Backup::remove_files( $id, $records[ $id ]['files'] ) ) { return false; }
				unset( $records[ $id ] );
			}
		}
		return Backup::persist( $records );
	} );
	if ( true !== $result ) { $failures[] = 'fixture backup cleanup failed'; }
	$dir = $upload['basedir'] . '/' . $state['run'];
	if ( is_dir( $dir ) && ! rmdir( $dir ) ) { $failures[] = 'fixture directory remains'; }
	$check( empty( $failures ), implode( '; ', $failures ) );
	echo "[flagged-https] fixture attachments, restored attachment, files and backup manifests cleaned\n";
	return;
}

$check( isset( $entries[ $branch ] ), 'Unknown fixture branch.' );
$entry = $entries[ $branch ];
$path = $upload['basedir'] . '/' . $entry['relative'];
$record = Backup::records()[ $entry['backup_id'] ] ?? null;
if ( 'intact' === $action ) {
	$check( get_post( $entry['attachment_id'] ) && is_file( $path ) && hash_file( 'sha256', $path ) === $entry['sha256'], 'Denied request mutated fixture attachment.' );
	$check( null === $record, 'Denied request created backup evidence.' );
} elseif ( 'deleted' === $action ) {
	$check( ! get_post( $entry['attachment_id'] ) && ! file_exists( $path ), 'Deletion did not remove attachment and file.' );
	if ( 'illegal_content_no_backup' === $branch ) {
		$check( null === $record && ! file_exists( $upload['basedir'] . '/' . Backup::PREFIX . $entry['backup_id'] ), 'No-backup branch retained a backup.' );
	} else {
		$check( is_array( $record ) && $record['attachment_id'] === $entry['attachment_id'], 'Durable backup record does not identify fixture.' );
		foreach ( $record['files'] as $index => $file ) {
			$copy = Backup::path( Backup::PREFIX . $entry['backup_id'] . '/' . $index . '.bin' );
			$check( is_file( $copy ) && hash_file( 'sha256', $copy ) === $file['sha256'], 'Retained backup bytes failed verification.' );
		}
	}
} elseif ( 'restored' === $action ) {
	$new_id = (int) ( $record['restored_attachment_id'] ?? 0 );
	$check( $new_id > 0 && $new_id !== $entry['attachment_id'] && get_post( $new_id ) && get_post( $new_id )->post_title === $state['run'], 'Restored attachment identity is invalid.' );
	$check( is_file( $path ) && hash_file( 'sha256', $path ) === $entry['sha256'], 'Restored bytes do not match the fixture.' );
	$check( $entry['relative'] === get_post_meta( $new_id, '_wp_attached_file', true ), 'Restored attachment file metadata is invalid.' );
} else {
	throw new RuntimeException( 'Unknown fixture action.' );
}
echo '[flagged-https] WordPress readback ' . $branch . ' ' . $action . ": ok\n";
