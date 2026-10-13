<?php
/** Signed Adapter -> Core -> Toolkit flagged-media acceptance. WP-CLI, disposable fixtures only. */
use Npcink\OpenClawAdapter\Rest\Controller;
use Npcink\OpenClawAdapter\Rest\Signing_Auth;
use Npcink_Abilities_Toolkit\Support\Flagged_Media_Backup as Backup;
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
	throw new RuntimeException( 'WordPress and sodium are required.' ); }
$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
if ( empty( $admins ) || ! wp_get_ability( 'npcink-abilities-toolkit/build-flagged-media-removal-plan' ) ) {
	throw new RuntimeException( 'Administrator and new Toolkit plan builder required.' ); }
$admin           = (int) $admins[0];
$run             = 'npcink-flagged-signed-' . bin2hex( random_bytes( 6 ) );
$pair            = sodium_crypto_sign_keypair();
$public          = sodium_crypto_sign_publickey( $pair );
$secret          = sodium_crypto_sign_secretkey( $pair );
$encode          = static function ( string $bytes ): string {
	return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
};
$key_id          = 'mk_' . substr( hash( 'sha256', $run ), 0, 24 );
$fingerprint     = 'sha256:' . hash( 'sha256', $public );
$keys            = get_option( Controller::CLIENT_KEYS_OPTION, array() );
$keys[ $key_id ] = array(
	'key_id'       => $key_id,
	'user_id'      => $admin,
	'public_key'   => $encode( $public ),
	'fingerprint'  => $fingerprint,
	'scopes'       => array( 'npcink.propose', 'npcink.execute', 'npcink.read', 'npcink.status' ),
	'created_at'   => gmdate( 'c' ),
	'last_used_at' => '',
	'revoked_at'   => '',
);
update_option( Controller::CLIENT_KEYS_OPTION, $keys, false );
$checks     = 0;
$ids        = array();
$proposals  = array();
$backups    = array();
$nonces     = array();
$paths      = array();
$check      = static function ( $condition, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	} };
$auth       = new Signing_Auth( static function () {} );
$request    = static function ( $method, $route, $params = array(), $signed = true ) use ( $admin, $key_id, $secret, $encode, $auth, &$nonces ) {
	wp_set_current_user( $signed ? 0 : $admin );
	$r = new WP_REST_Request( $method, $route );
	if ( 'GET' === $method ) {
		$r->set_query_params( $params );
	} else {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $params ) ); }
	if ( $signed ) {
		$timestamp = gmdate( 'c' );
		$nonce     = bin2hex( random_bytes( 16 ) );
		$nonces[]  = $nonce;
		$hash      = 'sha256:' . hash( 'sha256', $r->get_body() );
		foreach ( array(
			'key_id'         => $key_id,
			'timestamp'      => $timestamp,
			'nonce'          => $nonce,
			'content_sha256' => $hash,
			'signature_alg'  => 'Ed25519',
			'signature'      => $encode( sodium_crypto_sign_detached( $auth->canonical_string( $r, $timestamp, $nonce, $hash ), $secret ) ),
		) as $name => $value ) {
			$r->set_header( 'x_npcink_' . $name, $value ); }
	}
	$response = rest_do_request( $r );
	return array(
		'status' => $response->get_status(),
		'data'   => $response->get_data(),
	);
};
$require_ok = static function ( $result, $label ) use ( $check ) {
	$check( $result['status'] >= 200 && $result['status'] < 300, $label . ' HTTP ' . $result['status'] . ' ' . ( $result['data']['code'] ?? '' ) );
	return $result['data'];
};
$upload     = wp_upload_dir();
$dir        = $upload['basedir'] . '/' . $run;
wp_mkdir_p( $dir );
try {
	foreach ( array( 'governed_with_backup', 'illegal_content_no_backup' ) as $branch ) {
		wp_set_current_user( $admin );
		$relative = $run . '/' . $branch . '.jpg';
		$path     = $upload['basedir'] . '/' . $relative;
		$paths[]  = $path;
		file_put_contents( $path, 'signed fixture ' . $branch );
		$id    = wp_insert_attachment(
			array(
				'post_title'     => $run,
				'post_mime_type' => 'image/jpeg',
			),
			$path,
			0,
			true
		);
		$ids[] = $id;
		$check( ! is_wp_error( $id ) && $id > 0, 'attachment fixture created' );
		$plan_input = array(
			'attachment_id'          => $id,
			'deletion_policy_branch' => $branch,
			'reason'                 => 'Signed acceptance fixture only',
			'deletion_request_key'   => $run . '-' . $branch,
			'no_backup_confirmed'    => 'illegal_content_no_backup' === $branch,
		);
		if ( 'governed_with_backup' === $branch ) {
			$backups[] = Backup::id( $id, $plan_input['deletion_request_key'] ); }
		$plan = wp_get_ability( 'npcink-abilities-toolkit/build-flagged-media-removal-plan' )->execute( $plan_input );
		$check( ! is_wp_error( $plan ), 'Toolkit builder prepares branch plan' );
		$handoff     = $require_ok(
			$request(
				'POST',
				'/npcink-openclaw-adapter/v1/proposals/from-plan',
				array(
					'plan_ability_id' => 'npcink-abilities-toolkit/build-flagged-media-removal-plan',
					'plan'            => $plan['data'],
					'plan_input'      => $plan_input,
				)
			),
			'signed plan intake'
		);
		$proposal    = $handoff['proposals'][0] ?? array();
		$proposal_id = (string) ( $proposal['proposal_id'] ?? '' );
		$proposals[] = $proposal_id;
		$check( '' !== $proposal_id && 'pending' === $proposal['status'], 'Core holds flagged plan for manual approval' );
		$self = $request( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . $proposal_id . '/approve-and-execute' );
		$check( 403 === $self['status'] && get_post( $id ), 'signed client cannot self approve flagged deletion' );
		$premature = $request( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . $proposal_id . '/execute' );
		$check( $premature['status'] >= 400 && get_post( $id ), 'execution before human approval is denied' );
		$approval = array(
			'note'                            => 'Human approval of disposable signed fixture',
			'no_backup_attestation_confirmed' => true,
		);
		$require_ok( $request( 'POST', '/npcink-governance-core/v1/proposals/' . $proposal_id . '/approve', $approval, false ), 'human Core approval' );
		$execution = $require_ok( $request( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . $proposal_id . '/execute' ), 'signed approved execution' );
		$check( 'executed' === ( $execution['status'] ?? '' ) && ! get_post( $id ) && ! is_file( $path ), 'Toolkit deletion executes only after Core preflight' );
		$check( ! empty( $execution['correlation_id'] ) && false === $execution['core_commit_execution'], 'execution reports Core correlation and external mutation ownership' );
		$repeat = $request( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . $proposal_id . '/execute' );
		$check( $repeat['status'] >= 400, 'duplicate signed deletion execution rejected' );
		$backup_id = Backup::id( $id, $plan_input['deletion_request_key'] );
		if ( 'illegal_content_no_backup' === $branch ) {
			$check( ! isset( Backup::records()[ $backup_id ] ), 'illegal signed deletion creates no backup manifest' );
			continue; }
		$check( isset( Backup::records()[ $backup_id ] ), 'governed deletion evidence survives attachment deletion' );
		$restoration = $require_ok(
			$request(
				'POST',
				'/npcink-openclaw-adapter/v1/proposals',
				array(
					'ability_id' => 'npcink-abilities-toolkit/restore-media-backup',
					'title'      => $run,
					'summary'    => 'Restore disposable deleted attachment',
					'input'      => array(
						'attachment_id' => $id,
						'backup_id'     => $backup_id,
						'dry_run'       => true,
						'commit'        => false,
					),
					'preview'    => array( 'action' => 'restore_media_backup' ),
				)
			),
			'signed restore proposal'
		);
		$restore_id  = $restoration['proposal_id'];
		$proposals[] = $restore_id;
		$require_ok( $request( 'POST', '/npcink-governance-core/v1/proposals/' . $restore_id . '/approve', array( 'note' => 'Human restoration approval' ), false ), 'human restore approval' );
		$restored = $require_ok( $request( 'POST', '/npcink-openclaw-adapter/v1/proposals/' . $restore_id . '/execute' ), 'signed deleted attachment restoration' );
		$new_id   = (int) ( $restored['execution']['result']['attachment_id'] ?? 0 );
		$ids[]    = $new_id;
		$check( $new_id > 0 && $new_id !== $id && get_post( $new_id ) && 'signed fixture ' . $branch === file_get_contents( $path ), 'Adapter existing restore profile recreates exact fixture bytes with new attachment id' );
	}
} finally {
	wp_set_current_user( $admin );
	foreach ( $ids as $id ) {
		if ( is_int( $id ) && $id > 0 && get_post( $id ) ) {
			wp_delete_attachment( $id, true ); }
	}
	foreach ( $paths as $path ) {
		if ( is_file( $path ) ) {
			unlink( $path ); }
	}
	Backup::locked(
		static function () use ( $backups ) {
			$records = Backup::records();
			foreach ( $backups as $id ) {
				if ( isset( $records[ $id ] ) ) {
					Backup::remove_files( $id, $records[ $id ]['files'] );
					unset( $records[ $id ] );
				}
			} Backup::persist( $records );
		}
	);
	$keys = get_option( Controller::CLIENT_KEYS_OPTION, array() );
	unset( $keys[ $key_id ] );
	update_option( Controller::CLIENT_KEYS_OPTION, $keys, false );
	foreach ( $nonces as $nonce ) {
		delete_option( Controller::SIGNATURE_NONCE_OPTION_PREFIX . hash( 'sha256', $key_id . '|' . $nonce ) ); }
	foreach ( array( 'npcink_openclaw_adapter_execution_records', 'npcink_openclaw_adapter_preflight_handoffs' ) as $option ) {
		$records = get_option( $option, array() );
		foreach ( $records as $key => $record ) {
			if ( is_array( $record ) && in_array( $record['proposal_id'] ?? '', $proposals, true ) ) {
				unset( $records[ $key ] );
			}
		} update_option( $option, $records, false );
	}
	global $wpdb;
	foreach ( $proposals as $id ) {
		$wpdb->delete( $wpdb->prefix . 'npcink_governance_core_audit_log', array( 'proposal_id' => $id ) );
		$wpdb->delete( $wpdb->prefix . 'npcink_governance_core_proposals', array( 'proposal_id' => $id ) ); }
	if ( is_dir( $dir ) ) {
		rmdir( $dir ); }
	sodium_memzero( $secret );
}
echo "OK: {$checks} signed flagged-media acceptance checks\n";
