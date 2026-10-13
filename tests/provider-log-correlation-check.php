<?php
/** Fail-closed provider log acceptance, without creating synthetic log rows. */
function maa_adapter_verify_provider_log( bool $ai_active, array $fixture, array $execution, callable $read_log ): string {
	if ( ! $ai_active ) {
		return 'skipped: ai plugin absent';
	}
	if ( 'succeeded' !== ( $fixture['status'] ?? '' ) ) {
		throw new RuntimeException( 'AI provider fixture did not succeed: ' . ( $fixture['error_code'] ?? 'fixture_missing' ) );
	}
	foreach ( array( 'proposal_id', 'correlation_id', 'adapter_request_id' ) as $field ) {
		if ( ! is_string( $execution[ $field ] ?? null ) || '' === $execution[ $field ] ) {
			throw new RuntimeException( 'Execution correlation evidence is missing: ' . $field );
		}
	}
	foreach ( (array) ( $fixture['log_ids'] ?? array() ) as $id ) {
		$row = $read_log( $id );
		if ( ! is_array( $row ) || 'success' !== ( $row['status'] ?? '' ) || ( $row['provider'] ?? '' ) !== $fixture['provider'] || ( $row['model'] ?? '' ) !== $fixture['model'] ) {
			continue;
		}
		$context = (array) ( $row['context'] ?? array() );
		$core    = (array) ( $context['npcink_governance_core'] ?? array() );
		if ( ( $context['proposal_id'] ?? '' ) === $execution['proposal_id'] && ( $core['proposal_id'] ?? '' ) === $execution['proposal_id'] && ( $context['correlation_id'] ?? '' ) === $execution['correlation_id'] && ( $core['correlation_id'] ?? '' ) === $execution['correlation_id'] && ( $context['adapter_request_id'] ?? '' ) === $execution['adapter_request_id'] ) {
			return 'passed: persisted provider log matches signed execution';
		}
	}
	throw new RuntimeException( 'AI plugin is active but no persisted provider log matches the signed execution correlation.' );
}
