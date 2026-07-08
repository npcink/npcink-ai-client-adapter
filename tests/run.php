<?php
/**
 * Static contracts for Npcink AI Client Adapter.
 *
 * @package NpcinkOpenClawAdapter
 */

$root = dirname( __DIR__ );

/**
 * Assertion helper.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 */
function maa_adapter_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, '[fail] ' . $message . "\n" );
		exit( 1 );
	}
}

/**
 * Reads a file.
 *
 * @param string $path Path.
 * @return string
 */
function maa_adapter_read( string $path ): string {
	$contents = is_readable( $path ) ? file_get_contents( $path ) : false;
	return is_string( $contents ) ? $contents : '';
}

/**
 * Returns Adapter surfaces intentionally removed from the thin channel layer.
 *
 * @return array<int,string>
 */
function maa_adapter_removed_surface_texts(): array {
	return array(
		'/media-metadata-optimization',
		'media_metadata_optimization_route',
		'npcink-abilities-toolkit/optimize-media-metadata',
		'/media-derivative-runs',
		'/media-derivative-runs/(?P<run_id>[A-Za-z0-9._:-]+)',
		'/media-derivative-runs/(?P<run_id>[A-Za-z0-9._:-]+)/result',
		'/media-derivative-artifacts/(?P<artifact_id>[A-Za-z0-9._:-]+)/preview',
		'/media-derivative-proposal-payload',
		'can_use_media_derivative_artifact_preview',
		'create_media_derivative_run',
		'get_media_derivative_run',
		'get_media_derivative_run_result',
		'download_media_derivative_artifact_preview',
		'build_media_derivative_proposal_payload',
		'npcink-abilities-toolkit/build-media-derivative-cloud-request',
		'npcink_cloud_addon_dispatch_media_derivative_cloud_request',
		'npcink_cloud_addon_get_media_derivative_run',
		'npcink_cloud_addon_get_media_derivative_run_result',
		'npcink_cloud_addon_public_media_derivative_cloud_projection',
		'npcink_cloud_addon_build_media_derivative_optimization_payload',
		'npcink_governance_core_build_media_derivative_ability_input',
		"'crop'",
		"'watermark_enabled'",
		"isset( \$overrides['preferred_format'] ) && ! isset( \$overrides['target_format'] )",
		"isset( \$overrides['target_max_width'] ) && ! isset( \$overrides['max_width'] )",
		"unset( \$ability_input['target_format'], \$ability_input['max_width'], \$ability_input['watermark_enabled'] )",
		"unset( \$ability_input['watermark'] )",
		'npcink_governance_core_get_media_derivative_settings',
		'media_derivative_adapter_run.v1',
		'media_derivative_cloud_request.v1',
		'media_derivative_adapter_run_status.v1',
		'media_derivative_adapter_run_result.v1',
		'media_derivative_adapter_proposal_payload.v1',
		'media_derivative_artifact_preview_url',
		'media_derivative_artifact_preview_signature',
		'valid_media_derivative_artifact_preview_signature',
		'media_derivative_preview_expires_at',
		'preview_url',
		'preview_sig',
		'expires_ts',
		"wp_salt( 'auth' )",
		'X-Content-Type-Options: nosniff',
		'media_derivative_source_artifact',
		'media_derivative_watermark_artifact',
		"'text' === sanitize_key( (string) ( \$watermark['type'] ?? 'image' ) )",
		'attachment_upload_descriptor',
		'final_write_owner',
		'local_wordpress_host',
		'wordpress_write_included',
		'attachment_metadata_write_included',
		'core_proposal_required',
		'media_details_input',
		'optimization_payload_helper_available',
		'preferred_core_route',
		'legacy_derivative_proposal_payload_available',
		'ability_guard',
		'missing_capability_behavior',
		'surface_plan_ability_unavailable_do_not_split_into_two_proposals',
		'MAX_AI_SMOKE_PROMPT_CHARS',
		'MAX_MEDIA_DERIVATIVE_PREVIEW_BYTES',
		'public_media_derivative_artifact_descriptor',
		'npcink_openclaw_adapter_media_derivative_preview_too_large',
		'npcink_openclaw_adapter_ai_smoke_prompt_too_large',
		'/ai-provider-log-correlation-smoke',
		'POST /ai-provider-log-correlation-smoke',
		'ai_provider_log_correlation_smoke',
		'/site-info',
		'/wp-diagnostics-summary',
		'/wp-ops-diagnostics-detail',
		'site-summary',
		'active-plugins-detail',
		'plugin-conflict-diagnostics',
		'current-user-permissions',
		'php-extensions',
		'object-cache-status',
		'rewrite-rules-status',
		'ssl-https-status',
		'recent-error-log',
		'recent-error-log-tail',
		'database-info',
		'cron-events-detail',
		'custom-post-types',
		'roles-capabilities',
		'widgets-sidebars',
		'block-theme-assets',
		'search-index-status',
		'server-info',
		'integrations-status',
		'seo-summary',
		'security-summary',
		'performance-summary',
		"'media'",
		"'posts'",
		"'post-context'",
		"'users'",
		"'menu'",
		"'pages'",
		"'site-operations-dashboard'",
		'npcink-abilities-toolkit/site-info',
		'npcink-abilities-toolkit/wp-diagnostics-summary',
		'npcink-abilities-toolkit/wp-ops-diagnostics-detail',
		'npcink-abilities-toolkit/list-workflow-recipes',
		'npcink-abilities-toolkit/get-workflow-recipe',
		'npcink-abilities-toolkit/list-posts',
		'npcink-abilities-toolkit/get-post-context',
		'npcink-abilities-toolkit/list-media',
		'npcink-abilities-toolkit/resolve-media-attachment-by-url',
		'npcink-abilities-toolkit/list-users',
		'npcink-abilities-toolkit/get-menu',
		'npcink-abilities-toolkit/list-pages',
		'npcink-abilities-toolkit/get-site-operations-dashboard',
		'npcink-abilities-toolkit/build-content-inventory-fix-plan',
		'npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan',
		'npcink-abilities-toolkit/list-terms',
		'npcink-abilities-toolkit/list-taxonomy-terms',
		'npcink-abilities-toolkit/list-categories',
		'npcink-abilities-toolkit/list-tags',
		'npcink-abilities-toolkit/get-term',
		'npcink-abilities-toolkit/list-comments',
		'npcink-abilities-toolkit/resolve-internal-link-targets',
		'npcink-abilities-toolkit/get-post-stats',
		'npcink-abilities-toolkit/list-revisions',
		'npcink-abilities-toolkit/get-post-meta',
		'npcink-abilities-toolkit/get-page',
		'npcink-abilities-toolkit/inspect-page-structure',
		'npcink-abilities-toolkit/list-pages-tree',
		'npcink-abilities-toolkit/get-content-inventory-health',
		'npcink-abilities-toolkit/get-publishing-calendar-context',
		'npcink-abilities-toolkit/get-media-inventory-health',
		'npcink-abilities-toolkit/build-media-inventory-fix-plan',
		'npcink-abilities-toolkit/inspect-media-asset',
		'npcink-abilities-toolkit/get-taxonomy-inventory-health',
		'read_shortcut_definitions',
		'ops_diagnostics_input',
		'ops_diagnostics_plugin_conflict_input',
		'ops_diagnostics_log_input',
		'include_log_contents',
		'include_active_plugins',
		'include_inactive_plugins',
		'include_plugin_updates',
		'include_must_use_plugins',
		'include_dropins',
		'max_plugins_per_group',
		'tail_lines',
		'since_minutes',
		'not explicitly requested',
		'inactive plugin rows are not requested by default',
		'normalize_shortcut_input',
		'boolean_input_value',
		'include_delete_candidates',
		'include_trash_parent_media',
		'include_unattached_nonproduction_media',
		'plugin_conflict_input',
		'plugin_group_fields',
		'error_log_summary_fields',
		'workflow-recipes',
		'/workflow-recipe',
		'openclaw_recipes',
		'openclaw_recipe_index_row',
		'article_draft_plan',
		'article_batch_draft_plan',
		'article_media_batch_plan',
		'media_adoption_enhancement_plan',
		'article_block_plan',
		'block_theme_site_plan',
		'content_intent_router',
		'pattern_page_plan',
		'site_edit_router',
		'image_candidate_adoption_plan',
		'Compact route index only',
		'docs/openclaw-pattern-page-plan-recipe.md',
		'docs/openclaw-article-draft-plan-recipe.md',
		'docs/openclaw-article-batch-draft-plan-recipe.md',
		'docs/openclaw-article-media-batch-plan-recipe.md',
		'docs/openclaw-content-intent-router-contract.md',
		'docs/openclaw-site-edit-router-contract.md',
		'docs/openclaw-article-block-plan-recipe.md',
		'docs/openclaw-block-theme-site-builder-recipe.md',
		'docs/openclaw-pattern-page-research-brief-recipe.md',
		'docs/openclaw-pattern-page-with-visual-asset-recipe.md',
		'docs/openclaw-ai-image-ratio-crop-media-adoption-recipe.md',
		'docs/openclaw-image-candidate-adoption-plan-recipe.md',
		'docs/openclaw-media-adoption-enhancement-plan-recipe.md',
		'docs/openclaw-content-discoverability-recipe.md',
		'docs/openclaw-ai-article-writing-pack-recipe.md',
		'docs/openclaw-media-derivative-cloud-recipe.md',
		'docs/external-ai-client-contract.md',
		'visual_acceptance_required',
		'pattern_page_research_brief',
		'pattern_page_with_visual_asset_plan',
		'candidate_review_required',
		'local_media_url_required',
		'content_discoverability_suggestions',
		'ai_article_draft_with_discoverability',
		'media_derivative_cloud',
		'Media derivative Cloud artifact',
		'npcink-toolbox/get-content-discoverability-context',
		'npcink-toolbox/validate-content-discoverability-context',
		'npcink-toolbox/build-content-discoverability-brief',
		'npcink-toolbox/build-ai-article-writing-pack',
		'content-discoverability-context',
		'content-discoverability-validation',
		'content-discoverability-brief',
		'article-writing-pack',
		'suggestion_only',
		'draft_only',
		'block_native',
		'page_draft_only',
		'batch_approval',
		'image_source_attribution_required',
		'read_only_router',
		'prompt_is_not_authorization',
		'fail_closed',
		'read_only_contract',
		'navigation_and_global_styles_blocked',
		'template_blocks_only',
		'surface_inspection_required',
		'no_navigation_write',
		'bounded_external_evidence',
		'no_reference_copying',
		'two_stage',
		'signed_preview_is_temporary',
		'single_core_batch',
		'selected_asset_required',
		'direct_wordpress_write_false',
		'cloud_search_owner',
		'no_seo_meta_mutation',
		'suggestion_only_pack',
		'local_drafting_step',
		'proposal_required_for_writes',
		'read_shortcuts',
		'cloud_addon_health',
		'ai_provider',
		'ai_model',
		'wp_ai_client_prompt',
		'qwen3.5:0.8b',
	);
}

/**
 * Returns verbose admin connection text intentionally removed from default UI.
 *
 * @return array<int,string>
 */
function maa_adapter_removed_admin_connection_texts(): array {
	return array(
		'proposal_lookup',
		'render_proposal_lookup_result',
		'Open in Core',
		'Copy status URL',
		'Copy execute URL',
		'proposal_next_step_text',
		'$this->display_datetime( $created )',
		'$this->display_datetime( $updated )',
		'content_discoverability_suggestions',
		'ai_article_draft_with_discoverability',
		'pattern_page_research_brief',
		'content-discoverability-validation',
		'content-discoverability-context',
		'content-discoverability-brief',
		'article-writing-pack',
		'landing_page_research_brief',
		'competitor_research',
		'Return suggestions only; do not write SEO meta',
		'primary entrypoint is content-discoverability-brief',
		'do not copy reference site text, images, CSS',
		'Use article-writing-pack only for broad article requests',
		'WorkBuddy setup',
		'Copy WorkBuddy setup',
		'workbuddy_handoff_text',
		'Npcink AI Client Adapter WorkBuddy connection',
		'Developer routes, manifest, proposal diagnostics, and verbose handoff text are documented',
		'Proposal detail',
		'Show WorkBuddy setup',
		'Show full handoff text',
		'Show non-secret manifest',
		'Show env placeholder',
		'Copy full handoff text',
		'GET /help',
		'GET /proposals/{proposal_id}',
		'approve-and-execute',
		'Adapter execution profiles currently support npcink-abilities-toolkit/trash-post, npcink-abilities-toolkit/create-draft, npcink-abilities-toolkit/update-post, npcink-abilities-toolkit/patch-post-content, npcink-abilities-toolkit/update-post-blocks, npcink-abilities-toolkit/update-template-blocks, npcink-abilities-toolkit/upsert-template-blocks, npcink-abilities-toolkit/update-template-part-blocks, npcink-abilities-toolkit/patch-setting-value, npcink-abilities-toolkit/set-post-seo-meta, npcink-abilities-toolkit/set-post-slug, npcink-abilities-toolkit/set-post-terms, npcink-abilities-toolkit/delete-term, npcink-abilities-toolkit/update-media-details, npcink-abilities-toolkit/upload-media-from-url, npcink-abilities-toolkit/set-post-featured-image, npcink-abilities-toolkit/optimize-media-asset, npcink-abilities-toolkit/replace-media-file, npcink-abilities-toolkit/restore-media-backup, npcink-abilities-toolkit/adopt-cloud-media-derivative, npcink-abilities-toolkit/rename-media-file, npcink-abilities-toolkit/delete-media-permanently, npcink-abilities-toolkit/reply-comment, npcink-abilities-toolkit/trash-comment, and npcink-abilities-toolkit/approve-comment',
		'Adapter execute routes are final write paths and normalize ability input to dry_run=false and commit=true',
		'for dry-run-only verification, stop at commit-preflight and do not call execute',
		'Failure code handling',
		'npcink_openclaw_adapter_preflight_item_blocked',
		'AI Request Logs context',
		'wpai_request_log_context',
		'local_cli_new_session_opener_text',
		'log_context',
		'proposal_id',
		'correlation_id',
		'core_proxy_execute=false',
		'commit_execution=false',
		'<client-secret-field-value>',
		'already paired local Npcink AI Client Adapter profile',
		'Read client_policy from /help and treat it as machine-readable policy',
		'read_policy=core_read_authorization_required',
		'read-request create --profile=local',
		'read-request status --profile=local',
		'read-ability --profile=local',
		'local_cli_read_request_create_template',
		'local_cli_read_request_status_template',
		'local_cli_read_ability_template',
		'local_cli_setup_text',
		'local_cli_status_command',
		'--intent=preflight',
		'--intent=commit',
		'final execute routes require --intent=commit',
		'Do not read, cat, print, summarize, or copy the local keypair profile file',
	);
}

$main = maa_adapter_read( $root . '/npcink-ai-client-adapter.php' );
maa_adapter_assert( false !== strpos( $main, 'Plugin Name: Npcink AI Client Adapter' ), 'Main plugin has WordPress plugin header.' );
maa_adapter_assert( false !== strpos( $main, 'License: GPL-2.0-or-later' ), 'Main plugin declares GPL-compatible license.' );
maa_adapter_assert( false !== strpos( $main, 'Requires Plugins: npcink-abilities-toolkit, npcink-governance-core' ), 'Main plugin declares confirmed Toolkit and Core dependency slugs.' );
maa_adapter_assert( false !== strpos( $main, 'plugins_loaded' ), 'Main plugin boots on plugins_loaded.' );
maa_adapter_assert( false !== strpos( $main, "defined( 'NPCINK_OPENCLAW_ADAPTER_FILE' )" ), 'Main plugin is guarded against duplicate legacy bootstrap loading.' );
maa_adapter_assert( ! file_exists( $root . '/npcink-openclaw-adapter.php' ), 'Legacy bootstrap has been removed from the source tree.' );

$controller = maa_adapter_read( $root . '/includes/Rest/Controller.php' );
$supported_plan_abilities = maa_adapter_read( $root . '/includes/Rest/Supported_Plan_Abilities.php' );
$execution_profile_registry = maa_adapter_read( $root . '/includes/Rest/Execution_Profile_Registry.php' );
$controller_contract = $controller . "\n" . $supported_plan_abilities . "\n" . $execution_profile_registry;
maa_adapter_assert( false !== strpos( $supported_plan_abilities, 'final class Supported_Plan_Abilities' ), 'Supported plan ability registry exists.' );
maa_adapter_assert( false !== strpos( $supported_plan_abilities, 'public static function ids' ), 'Supported plan ability exposes ids.' );
maa_adapter_assert( false !== strpos( $supported_plan_abilities, 'public static function contains' ), 'Supported plan ability exposes membership checks.' );
maa_adapter_assert( false !== strpos( $supported_plan_abilities, 'npcink-abilities-toolkit/build-content-metadata-apply-plan' ), 'Supported plan ability registry accepts Toolkit content metadata apply plans.' );
maa_adapter_assert( false !== strpos( $execution_profile_registry, 'final class Execution_Profile_Registry' ), 'Execution profile registry exists.' );
maa_adapter_assert( false !== strpos( $execution_profile_registry, 'public static function profiles' ), 'Execution profile registry exposes profiles.' );
maa_adapter_assert( false !== strpos( $execution_profile_registry, 'npcink-abilities-toolkit/update-post-blocks' ), 'Execution profile registry keeps governed block writes supported.' );
foreach ( array( 'apply_filters', 'do_action', 'add_filter', 'add_action', 'get_option', 'update_option', 'wp_remote_', '$wpdb', 'register_post_type' ) as $dynamic_extension_signal ) {
	maa_adapter_assert( false === strpos( $execution_profile_registry, $dynamic_extension_signal ), 'Execution profile registry does not use dynamic extension signal: ' . $dynamic_extension_signal );
}
preg_match_all( "/'([^']+)'\\s*=>\\s*array\\s*\\(\\s*\\n\\s*'supported_input_fields'/", $execution_profile_registry, $execution_profile_matches );
$execution_profile_ids = $execution_profile_matches[1] ?? array();
maa_adapter_assert( count( $execution_profile_ids ) >= 20, 'Execution profile registry exposes the expected explicit profile set.' );
foreach ( $execution_profile_ids as $execution_profile_id ) {
	maa_adapter_assert( 1 === preg_match( '/^npcink-abilities-toolkit\\/[a-z0-9-]+$/', $execution_profile_id ), 'Execution profile uses a literal Toolkit ability id: ' . $execution_profile_id );
	maa_adapter_assert( false === strpos( $execution_profile_id, '*' ), 'Execution profile does not use wildcard ability id: ' . $execution_profile_id );
}
$supported_input_field_count = substr_count( $execution_profile_registry, "'supported_input_fields'" );
maa_adapter_assert( count( $execution_profile_ids ) === $supported_input_field_count, 'Every execution profile starts with a supported_input_fields whitelist.' );
maa_adapter_assert( false !== strpos( $controller, 'npcink-abilities-toolkit/get-post-blocks' ), 'Controller can read back post blocks after execution.' );
maa_adapter_assert( false !== strpos( $controller, 'npcink-abilities-toolkit/get-template-blocks' ), 'Controller can read back template blocks after execution.' );
maa_adapter_assert( false !== strpos( $controller, 'npcink-abilities-toolkit/get-template-part-blocks' ), 'Controller can read back template part blocks after execution.' );
foreach (
	array(
		'/media-metadata-optimization',
		'media_metadata_optimization_route',
		'/media-derivative-runs',
		'/media-derivative-proposal-payload',
		'can_use_media_derivative_artifact_preview',
		'create_media_derivative_run',
		'get_media_derivative_run',
		'get_media_derivative_run_result',
		'download_media_derivative_artifact_preview',
		'build_media_derivative_proposal_payload',
		'/ai-provider-log-correlation-smoke',
		'ai_provider_log_correlation_smoke',
		'read_shortcut_definitions',
		'normalize_shortcut_input',
		'workflow-recipes',
		'/workflow-recipe',
		'openclaw_recipes',
		'openclaw_recipe_index_row',
		'cloud_addon_health',
		'wp_ai_client_prompt',
	) as $removed_surface_text
) {
	maa_adapter_assert( false === strpos( $controller, $removed_surface_text ), 'Controller removes non-core Adapter surface: ' . $removed_surface_text );
}
foreach (
	array(
		'docs/architecture/adapter-boundary.md'    => array(
			'thin OpenClaw channel layer',
			'core_proxy_execute=false',
			'commit_execution=false',
			'Client-key fingerprint binding is intentionally cross-plugin',
			'/npcink-governance-core/v1/contract',
			'/npcink-abilities-toolkit/v1/contract',
		),
		'docs/adapter-contract-reuse-readiness-2026-07-08.md' => array(
			'Adapter Contract Reuse Readiness',
			'execution_profiles',
			'ability_contracts',
			'proposal_handoff',
			'product_surface',
			'signed_transport',
			'runtime_detail',
			'No new Adapter route, execution profile, or runtime code is needed for this pass.',
			'execution_handoff_posture',
			'dependency_contracts',
			'implementation_posture_evidence',
			'Execution_Profile_Registry',
			'Supported_Plan_Abilities',
			'POST /run-read-ability',
			'POST /proposals/from-plan',
			'POST /proposals/{proposal_id}/commit-preflight',
			'Stop and write a boundary note or ADR',
			'generic final write authority',
			'npcink-workflow-toolbox',
			'composer test:all',
		),
		'docs/contracts/client-policy.md'         => array(
			'npcink_openclaw_adapter_client_policy.v1',
			'adapter_only_fail_closed',
			'ability_id_plus_input_hash',
			'proposal -> Core approval -> commit-preflight -> explicit commit intent -> Adapter execution profile',
		),
		'docs/contracts/execution-profiles.md'    => array(
			'Adapter execution profiles are the only Adapter-owned final-write allowlist',
			'Execution_Profile_Registry.php',
			'Placement And Extension Rules',
			'Keep the execution profile registry in Adapter',
			'Do not expose dynamic extension points for execution profiles',
			'WordPress filters, actions, options, database rows, remote',
			'configuration, wildcards, category matches, or ability-id patterns',
			'Profile Admission Checklist',
			'Bind exactly one Toolkit ability id.',
			'Use a literal `npcink-abilities-toolkit/<ability>` id',
			'Require Core approval and Core commit-preflight evidence',
			'reject undeclared write fields',
			'execution_profile_registry_hash',
			'block-theme-template.update_draft',
			'npcink-abilities-toolkit/update-template-blocks',
		),
		'docs/external-ai-client-contract.md'     => array(
			'External AI Client Contract',
			'The client connects to `npcink-ai-client-adapter` only',
			'a second Governance Core API',
			'a provider/model runtime',
			'a Cloud connector',
			'Use `POST /run-read-ability` with a real `ability_id` from `/capabilities`',
			'client -> Adapter -> Core proposal -> Core approval -> Core commit-preflight -> Adapter execution profile -> WordPress Abilities API',
			'Adapter does not expose provider/model smoke routes',
			'Adapter does not own Cloud run creation',
			'The client should surface `data.operator_feedback` when present',
		),
		'docs/openclaw-zhihu-research-atomics.md' => array(
			'OpenClaw Zhihu Research Atomics',
			'openclaw_atoms.zhihu_hot_topics',
			'openclaw_atoms.zhihu_search',
			'openclaw_atoms.global_search',
			'openclaw_atoms.zhida_answer',
			'npcink-toolbox/cloud-web-search',
			'managed_source=zhihu_hot_topics',
			'managed_source=zhihu_research',
			'managed_source=zhihu_global_search',
			'managed_source=zhida_deep',
			'POST /wp-json/npcink-openclaw-adapter/v1/run-read-ability',
			'tests/fixtures/openclaw-zhihu-atomics/',
			'composer accept:openclaw-zhihu-atomics',
			'topic_candidate.v1',
			'source_evidence.v1',
			'grounded_answer.v1',
			'article_research_pack.v1',
			'write_posture=suggestion_only',
			'direct_wordpress_write=false',
			'Adapter must not register /cloud/* routes',
			'Cloud Addon owns Cloud credentials',
		),
		'docs/acceptance/local-ai-client-smoke.md' => array(
			'composer accept:local-ai-client',
			'composer accept:local-ai-client-fixture',
			'MAA_ADAPTER_FIXTURE_ALLOW_COMMIT=1',
			'composer smoke:package-install',
		),
	) as $doc_path => $required_doc_texts
) {
	$doc = maa_adapter_read( $root . '/' . $doc_path );
	maa_adapter_assert( '' !== $doc, 'Documentation exists: ' . $doc_path );
	foreach ( $required_doc_texts as $required_doc_text ) {
		maa_adapter_assert( false !== strpos( $doc, $required_doc_text ), 'Documentation contains required text in ' . $doc_path . ': ' . $required_doc_text );
	}
}
foreach (
	array(
		'npcink-abilities-toolkit/build-content-inventory-fix-plan',
		'npcink-abilities-toolkit/build-media-optimization-plan',
		'npcink-abilities-toolkit/build-article-optimization-apply-plan',
		'npcink-abilities-toolkit/build-pattern-page-plan',
		'npcink-toolbox/build-site-knowledge-review-plan',
		'npcink-toolbox/build-nightly-inspection-review-plan',
	) as $required_plan_ability
) {
	maa_adapter_assert( false !== strpos( $supported_plan_abilities, $required_plan_ability ), 'Supported plan ability contains required ability: ' . $required_plan_ability );
}
	foreach (
		array(
		'npcink-openclaw-adapter/v1',
		'/health',
		'/help',
		'dependency_status',
		'missing_dependency_for_route',
		'npcink_openclaw_adapter_missing_dependency',
		'adapter_entry_with_separate_governance_and_ability_plugins',
		'npcink_abilities_toolkit_get_registered',
		'/capabilities',
		'/connection/manifest',
		'/connect/device/start',
		'/connect/device/poll',
		'/connection/key-pairs',
		'connection_manifest',
		'start_device_pairing',
			'poll_device_pairing',
			'approve_device_pairing',
			'revoke_client_key_by_id',
			'authenticate_signed_request',
			'current_signed_client_fingerprint',
			'sanitize_signed_client_fingerprint',
			'signed_request_credentials',
		'DEVICE_PAIRING_TTL',
		'SIGNATURE_NONCE_TTL',
		'key_pair_device_pairing',
		'npcink-key-pair-auth.v1',
		'x_npcink_key_id',
		'Npcink-Signature',
		'Ed25519',
		'/run-read-ability',
		'/read-requests',
		'/read-requests/(?P<request_id>[A-Za-z0-9_-]+)',
		"'/read-preflight'",
		'read_authorization_params',
		'core_read_authorization_preflight',
		'validate_core_read_authorization_context',
		'read_authorization_granted',
		'core_authorization_truth',
		'commit_execution',
		'write_execution',
		'client_policy',
		'npcink_openclaw_adapter_client_policy.v1',
		'policy_version',
		'adapter_contract_metadata',
		'dependency_contracts',
			'dependency_contracts_ready',
			'dependency_contract_summary',
			'dependency_contract_boundary_summary',
			'contract_semantics_supported',
			'core_boundary_supported',
			'context_bindings',
			'site_binding',
			'signed_client_fingerprint_binding',
			'implementation_posture_supported',
			'toolkit_boundary_supported',
			'schema_controls_supported',
			'write_controls_supported',
			'forbidden_payloads_omitted',
			'supported_when_forwarded_by_trusted_adapter',
			'rest_error_code_from_data',
		'npcink_openclaw_adapter_contract.v1',
		'adapter_contract_version',
		'execution_profile_registry_version',
		'supported_plan_abilities_version',
		'core_contract_min_version',
		'core_plugin_min_version',
		'toolkit_contract_min_version',
		'toolkit_plugin_min_version',
		'execution_profile_registry_hash',
		'supported_execute_ability_ids_hash',
		'supported_plan_ability_ids_hash',
		'contract_sha256',
		'contract_hash_value',
		'/npcink-governance-core/v1/contract',
		'/npcink-abilities-toolkit/v1/contract',
		'npcink_governance_core_contract.v1',
		'npcink_abilities_toolkit_contract.v1',
		'provider_secret_storage',
		'host_governed_writes',
		'ability_catalog_source',
		'wordpress_abilities_api',
		'input_schema_source',
		'output_schema_source',
		'callback_free_hashes',
		'stable_contract_hashes',
		'read_execution_surface',
		'write_execution_surface',
		'forbidden_payloads_omitted',
		'adapter_only_fail_closed',
		'forbidden_outputs',
		'forbidden_local_access',
		'keypair_profile_files',
		'database_direct',
		'filesystem_secret_read_allowed',
		'ability_id_plus_input_hash',
			'npcink_openclaw_adapter_core_read_grant_site_url_mismatch',
			'npcink_openclaw_adapter_core_read_grant_blog_id_mismatch',
			'npcink_openclaw_adapter_core_read_grant_signed_client_fingerprint_mismatch',
			'signed_client_fingerprint',
			'client_key_fingerprint',
			'site_url',
		'home_url',
		'blog_id',
		'run_read_ability( string $ability_id, array $input, array $log_context = array(), array $read_authorization = array() )',
		'/media-metadata-optimization',
		'media_metadata_optimization_route',
		'npcink-abilities-toolkit/optimize-media-metadata',
		'/media-derivative-runs',
		'/media-derivative-runs/(?P<run_id>[A-Za-z0-9._:-]+)',
		'/media-derivative-runs/(?P<run_id>[A-Za-z0-9._:-]+)/result',
		'/media-derivative-artifacts/(?P<artifact_id>[A-Za-z0-9._:-]+)/preview',
		'/media-derivative-proposal-payload',
		'can_use_media_derivative_artifact_preview',
		'create_media_derivative_run',
		'get_media_derivative_run',
		'get_media_derivative_run_result',
		'download_media_derivative_artifact_preview',
			'build_media_derivative_proposal_payload',
			'npcink-abilities-toolkit/build-media-derivative-cloud-request',
			'npcink_cloud_addon_dispatch_media_derivative_cloud_request',
			'npcink_cloud_addon_get_media_derivative_run',
			'npcink_cloud_addon_get_media_derivative_run_result',
			'npcink_cloud_addon_public_media_derivative_cloud_projection',
			'npcink_cloud_addon_build_media_derivative_optimization_payload',
			'npcink_cloud_addon_download_media_derivative_artifact',
			'npcink_governance_core_build_media_derivative_ability_input',
		"'crop'",
		"'watermark_enabled'",
		"isset( \$overrides['preferred_format'] ) && ! isset( \$overrides['target_format'] )",
		"isset( \$overrides['target_max_width'] ) && ! isset( \$overrides['max_width'] )",
		"unset( \$ability_input['target_format'], \$ability_input['max_width'], \$ability_input['watermark_enabled'] )",
		"unset( \$ability_input['watermark'] )",
		'npcink_governance_core_get_media_derivative_settings',
		'media_derivative_adapter_run.v1',
		'media_derivative_cloud_request.v1',
		'media_derivative_adapter_run_status.v1',
		'media_derivative_adapter_run_result.v1',
		'media_derivative_adapter_proposal_payload.v1',
		'media_derivative_artifact_preview_url',
		'media_derivative_artifact_preview_signature',
		'valid_media_derivative_artifact_preview_signature',
		'media_derivative_preview_expires_at',
		'preview_url',
		'preview_sig',
		'expires_ts',
		'wp_salt( \'auth\' )',
		'X-Content-Type-Options: nosniff',
		'media_derivative_source_artifact',
		'media_derivative_watermark_artifact',
		"'text' === sanitize_key( (string) ( \$watermark['type'] ?? 'image' ) )",
		'attachment_upload_descriptor',
		'final_write_owner',
		'local_wordpress_host',
		'wordpress_write_included',
		'attachment_metadata_write_included',
		'core_proposal_required',
		'/ai-provider-log-correlation-smoke',
		'ai_provider_log_correlation_smoke',
		'observability_request_context',
		'safe_observability_context',
		'adapter.core.request',
		'adapter.proposal.create',
		'adapter.proposal.plan_ingest',
		'adapter.commit.preflight',
		'adapter.proposal.execute',
		'status_code',
		'/site-info',
		'site-summary',
			'/wp-diagnostics-summary',
			'/wp-ops-diagnostics-detail',
			'active-plugins-detail',
			'plugin-conflict-diagnostics',
			'current-user-permissions',
			'php-extensions',
			'object-cache-status',
			'rewrite-rules-status',
			'ssl-https-status',
			'recent-error-log',
			'recent-error-log-tail',
			'database-info',
			'cron-events-detail',
			'custom-post-types',
			'roles-capabilities',
			'widgets-sidebars',
			'block-theme-assets',
			'search-index-status',
			'server-info',
			'integrations-status',
			'seo-summary',
			'security-summary',
			'performance-summary',
			'read_shortcut_definitions',
			'ops_diagnostics_input',
			'ops_diagnostics_plugin_conflict_input',
			'ops_diagnostics_log_input',
			'include_log_contents',
			'include_active_plugins',
			'include_inactive_plugins',
			'include_plugin_updates',
			'include_must_use_plugins',
			'include_dropins',
			'max_plugins_per_group',
			'tail_lines',
			'since_minutes',
			'not explicitly requested',
			'inactive plugin rows are not requested by default',
			'route_groups',
			'help_route_groups',
			'help_routes_flat',
			'help_route_purpose',
			'GET /term?id={id}',
			'normalize_shortcut_input',
			'boolean_input_value',
			'include_delete_candidates',
			'include_trash_parent_media',
			'include_unattached_nonproduction_media',
			'supported_plan_ability_ids',
			'npcink_openclaw_adapter_plan_ability_unsupported',
			'execution_profiles',
			'validate_proposal_create_input',
			'validate_plan_write_action_inputs',
			'invalid_output_reference_token',
			'supported_execute_ability_ids',
			'supported_input_fields',
			'enum_fields',
			'npcink_openclaw_adapter_ability_input_field_unsupported',
			'source_type',
			'npcink_openclaw_adapter_media_source_type_invalid',
			'owned',
			'ai_generated',
			'stock',
			'external',
			'test',
			'npcink_openclaw_adapter_plan_action_input_invalid',
			'validate_output_references',
			'resolve_output_references',
			'npcink_openclaw_adapter_output_reference_unavailable',
			'npcink_openclaw_adapter_output_reference_unresolved',
			'post_id_from_result',
			'force_post_input',
			'required_slug_fields',
			'validate_terms_input',
			'validate_delete_term_input',
			'execute_approved_proposal_route',
			'approve_and_execute_proposal_route',
			'/execute-approved-proposal',
			'/proposals/(?P<proposal_id>[A-Za-z0-9_-]+)/execute',
			'/proposals/(?P<proposal_id>[A-Za-z0-9_-]+)/approve-and-execute',
			'approved_proposal_execution_routes',
			'approved_proposal_execution',
			'unified_approve_and_execute',
			'core_approval_then_adapter_execution',
			'npcink_openclaw_adapter_unified_action',
			'core_approved_commit_preflight_required',
			'wp_abilities_rest_after_core_preflight',
			'MAX_EXECUTION_ACTIONS',
				'MAX_DEVICE_PAIRINGS',
				'MAX_DEVICE_PAIRING_STARTS_PER_WINDOW',
				'MAX_DEVICE_PAIRING_POLLS_PER_WINDOW',
				'DEVICE_PAIRING_POLL_RATE_LIMIT_TTL',
				'MAX_DEVICE_PAIRING_BODY_BYTES',
				'MAX_DEVICE_PAIRING_POLL_BODY_BYTES',
				'EXECUTION_LOCK_TTL',
				'DISCOVERY_CACHE_TTL',
				'PREFLIGHT_HANDOFF_RETENTION_TTL',
				'EXECUTION_RECORD_RETENTION_TTL',
				'MAX_UPSTREAM_ERROR_DETAIL_BYTES',
				'CLIENT_KEY_LAST_USED_WRITE_TTL',
			'MAX_REST_BODY_BYTES',
			'MAX_ACTION_INPUT_BYTES',
			'MAX_BLOCK_ITEMS',
			'MAX_OPERATION_ITEMS',
			'MAX_TERM_ITEMS',
			'MAX_PROPOSAL_LIST_LIMIT',
			'MAX_AI_SMOKE_PROMPT_CHARS',
			'MAX_LIGHT_POST_BODY_BYTES',
			'MAX_MEDIA_DERIVATIVE_PREVIEW_BYTES',
			'can_use_admin_session',
				'validate_request_body_size',
				'enforce_device_pairing_start_rate_limit',
				'enforce_device_pairing_poll_rate_limit',
				'request_rate_limit_fingerprint',
				'bounded_text_field',
			'should_update_client_key_last_used',
			'validate_execute_action_input_size',
			'public_media_derivative_artifact_descriptor',
			'normalize_plan_batch_metadata',
			'npcink_openclaw_adapter_request_body_too_large',
			'npcink_openclaw_adapter_device_pairing_rate_limited',
			'npcink_openclaw_adapter_action_input_too_large',
			'npcink_openclaw_adapter_action_items_limit_exceeded',
			'npcink_openclaw_adapter_ai_smoke_prompt_too_large',
			'npcink_openclaw_adapter_media_derivative_preview_too_large',
			'execution_input_contract',
			'partial_success',
			'selected_batch_execution_summary',
			'execution_profile_id_for_ability',
			'execution_action_idempotency_key',
			'batch_write_actions',
			'normalize_execution_actions',
			'execute_normalized_action',
			'npcink_openclaw_adapter_execute_profile_unsupported',
			'npcink_openclaw_adapter_execution_input_ambiguous',
			'npcink_openclaw_adapter_write_action_invalid',
			'npcink_openclaw_adapter_write_action_target_required',
			'npcink_openclaw_adapter_write_actions_limit_exceeded',
			'npcink_openclaw_adapter_write_action_core_proxy_execute_unsupported',
			'npcink_openclaw_adapter_write_action_commit_execution_unsupported',
			'npcink_openclaw_adapter_preflight_not_authorized',
			'npcink_openclaw_adapter_core_execution_unsupported',
			'npcink_openclaw_adapter_proposal_rejected',
			'npcink_openclaw_adapter_preflight_item_blocked',
			'operator_feedback',
			'error_with_operator_feedback',
			'batch_review_feedback',
			'batch_review_feedback_from_proposals',
			'batch_review_feedback_from_preflight',
			'batch_review_feedback_from_summary',
			'npcink_openclaw_adapter_batch_review_feedback.v1',
			'core-batch-review-summary-v1',
			'selected_count',
			'submitted_count',
			'blocked_count',
			'retryable',
			'operator_next_action',
			'review_partial_failure_and_create_revised_proposal',
			'core_preflight_evidence',
			'implementation_posture_evidence',
			'implementation_posture_execution_evidence',
			'npcink_openclaw_adapter_implementation_posture_evidence.v1',
			'npcink_openclaw_adapter_implementation_posture_invalid',
			'npcink_openclaw_adapter_implementation_posture_forbidden_ownership',
			'npcink_openclaw_adapter_implementation_posture_mismatch',
			'execution_handoff_posture',
			'npcink_openclaw_adapter_execution_handoff_posture.v1',
			'governance_truth_owner',
			'commit_preflight_truth',
			'generic_write_executor',
			'operator_block_guidance',
			'execution_profile',
			'idempotency_key',
			'resolve_blocked_items_before_commit_preflight',
			'final_execution_owner',
			'plan_handoff_operator_feedback',
			'proposal_status_operator_feedback',
			'preflight_operator_feedback',
			'can_retry_after_revision',
			'core_evidence',
			'npcink_openclaw_adapter_preflight_correlation_required',
				'execute_core_approved_proposal',
				'execute_core_approved_proposal_locked',
				'acquire_execution_lock',
				'release_execution_lock',
				'EXECUTION_RECORDS_OPTION',
			'PREFLIGHT_HANDOFFS_OPTION',
			'MAX_PREFLIGHT_HANDOFFS',
			'preflight_handoffs',
			'store_preflight_handoff',
			'contract_preflight',
			'consume_cached_preflight_handoff',
			'prune_preflight_handoffs',
			'validate_preflight_binding',
			'validate_execution_handoff_binding',
			'proposal_handoff_ability_ids',
			'validate_core_context_site_binding',
			'validate_core_context_expiry',
			'validate_core_context_signed_client_binding',
			'proposal_input_hash',
			'npcink_openclaw_adapter_preflight_input_hash_mismatch',
			'npcink_openclaw_adapter_preflight_policy_version_invalid',
			'npcink_openclaw_adapter_preflight_expired',
			'npcink_openclaw_adapter_preflight_handoff_missing',
			'npcink_openclaw_adapter_preflight_handoff_executor_invalid',
			'npcink_openclaw_adapter_preflight_handoff_execution_surface_invalid',
			'npcink_openclaw_adapter_preflight_handoff_core_proxy_execute_unsupported',
			'npcink_openclaw_adapter_preflight_handoff_commit_execution_unsupported',
			'npcink_openclaw_adapter_preflight_handoff_proposal_mismatch',
			'npcink_openclaw_adapter_preflight_handoff_ability_mismatch',
			'npcink_openclaw_adapter_preflight_handoff_correlation_mismatch',
			'npcink_openclaw_adapter_preflight_site_url_mismatch',
			'npcink_openclaw_adapter_preflight_handoff_blog_id_mismatch',
			'npcink_openclaw_adapter_preflight_signed_client_fingerprint_mismatch',
			'npcink_openclaw_adapter_preflight_handoff_signed_client_fingerprint_mismatch',
			'core-preflight-v1',
			'adapter_after_core_preflight',
			'wp_abilities_rest',
			'approved_input_hash',
			'policy_version',
			'adapter_preflight_handoff_cached',
			'adapter_preflight_source',
			'npcink_governance_core_commit_preflight_already_issued',
			'completed_execution_record',
				'store_completed_execution_record',
				'store_failed_execution_record',
				'public_execution_response_payload',
				'request_wants_full_execution_detail',
				'record_core_execution_result',
			'/record-execution',
			'core_execution_record',
			'failed_action_id',
			'failed_action_index',
			'failed_execution_profile',
			'failed_idempotency_key',
				'npcink_openclaw_adapter_execution_already_completed',
				'npcink_openclaw_adapter_execution_in_progress',
				'execution_record',
			'augment_proposal_status_response',
			'proposal_derived_execution_status',
			'media_optimization_readiness',
			'/media-optimization-readiness',
			'get_proposal_media_optimization_readiness',
			'cloud_addon_health',
			'cloud_addon',
			'adapter_status',
			'execution_status',
			'effective_status',
			'executable',
			'non_executable_reason',
			'preflight_status',
			'review_summary',
			'proposal_review_summary',
			'preflight_already_issued',
			'already_executed',
			'cloud_artifact_download_available',
			'artifact_not_expired',
			'adapter_validator_aligned',
			'content_reference_scan_completed',
			'normalize_media_optimization_reference_repairs',
			'patch_preview',
			'old_url_absent',
			'new_url_present',
				'actual_replacement_count',
				'unmatched_rules',
				'compact_execution_verification',
				'block_write_readback_verification',
				'sanitize_public_verification_summary',
				'aggregate_execution_verification',
				'block_readback_status',
				'block_readback_verified_count',
				'post_reference_count',
				'post_reference_old_urls_absent',
				'post_reference_new_urls_present',
			'get_core_proposal_data',
			'validate_execute_ability',
			'dispatch_upstream_with_runtime_context',
			'npcink_ai_runtime_wp_ability_context',
			'sanitize_runtime_context',
			'approval_commit_authorized',
			'approval_context',
			'proposal_caller_context',
			"'term_id'",
			'plugin_conflict_input',
			'plugin_group_fields',
			'error_log_summary_fields',
		'workflow-recipes',
		'/workflow-recipe',
			'openclaw_recipes',
			'article_draft_plan',
			'article_media_batch_plan',
			'pattern_page_plan',
			'Compact route index only',
			'openclaw_recipe_index_row',
			'docs/openclaw-pattern-page-plan-recipe.md',
			'visual_acceptance_required',
			'pattern_page_research_brief',
			'pattern_page_with_visual_asset_plan',
			'candidate_review_required',
			'local_media_url_required',
			'content_discoverability_suggestions',
			'ai_article_draft_with_discoverability',
			'media_derivative_cloud',
		'Media derivative Cloud artifact',
			'npcink-toolbox/get-content-discoverability-context',
			'npcink-toolbox/validate-content-discoverability-context',
			'npcink-toolbox/build-content-discoverability-brief',
			'npcink-toolbox/build-ai-article-writing-pack',
			'content-discoverability-context',
			'content-discoverability-validation',
			'content-discoverability-brief',
			'article-writing-pack',
			'suggestion_only',
		"'media'",
		"'posts'",
		"'post-context'",
		"'users'",
		"'menu'",
		"'pages'",
		"'site-operations-dashboard'",
		'/proposals',
			'/proposals/from-plan',
			'create_proposals_from_plan',
			'list_proposals',
			'get_proposal',
			'/commit-preflight',
		"current_user_can( 'manage_options' )",
		'/npcink-governance-core/v1/capabilities',
		'/npcink-governance-core/v1/proposals',
		"caller_type' => 'openclaw_adapter'",
		"'via'         => 'npcink-ai-client-adapter'",
		'/wp-abilities/v1/abilities/',
		'governance_mode',
		'direct_read',
		'read_policy',
		'direct_read_public',
		'direct_read_internal',
		'direct_read_sensitive',
		'core_read_authorization_required',
		'sensitivity',
		'redaction_required',
		'read_authorization_required',
		'core_read_authorization_required_error',
		'npcink_openclaw_adapter_core_read_authorization_required',
		'redaction_applied',
		'redaction_summary',
		'read_governance_context',
		'apply_read_redaction',
		'is_sensitive_read_key',
		'read_context',
		'proposal_required',
		'wp_abilities_rest',
		'adapter_after_core_preflight',
		'direct_read_plan_to_core_proposals',
		'plan_to_proposal',
		'core_proxy_execute',
		'commit_execution',
		'plan_fields_preserved',
		'batch_id',
		'issue_types',
		'attachment_ids',
		'post_ids',
		'write_actions',
		'manual_review',
		'skipped_destructive_candidates',
		'issue_counts',
		'action_count',
		'adapter_base_url',
		'help_url',
		'proposal_list_url',
		'proposal_detail_url',
		'read_shortcuts',
			'wordpress_rest_application_password',
			'proposal_status',
			'proposals:read',
			'approval_surface',
		'npcink_governance_core_admin',
		'log_context',
		'wpai_request_log_context',
		'append_ai_request_log_context',
		'with_ai_request_log_context',
		'ai_request_log_context',
		'proposal_id',
		'correlation_id',
		'adapter_request_id',
		'adapter_route',
		'ai_provider',
		'ai_model',
		'governance_source',
		'npcink_governance_core',
		'wp_ai_client_prompt',
		'qwen3.5:0.8b',
		'POST /ai-provider-log-correlation-smoke',
		'npcink_openclaw_adapter',
			'proposal_status_routes',
			'plan_proposal_routes',
			'core_app_token_configured',
			'core_app_token_source',
				'core_app_token_required_scopes',
			'GET /proposals',
			'GET /proposals/{proposal_id}',
			'approve-and-execute',
			'NPCINK_OPENCLAW_ADAPTER_CORE_APP_TOKEN',
		'npcink_openclaw_adapter_core_app_token',
			'x-npcink-governance-core-app-token',
			'core_capabilities_data',
			'public_upstream_error_data',
			'sanitize_public_response_value',
			'npcink_openclaw_adapter_proposal_required',
		'npcink-abilities-toolkit/site-info',
			'npcink-abilities-toolkit/site-info',
			'npcink-abilities-toolkit/wp-diagnostics-summary',
			'npcink-abilities-toolkit/wp-ops-diagnostics-detail',
			'npcink-abilities-toolkit/list-workflow-recipes',
		'npcink-abilities-toolkit/get-workflow-recipe',
		'npcink-abilities-toolkit/list-posts',
		'npcink-abilities-toolkit/get-post-context',
		'npcink-abilities-toolkit/list-media',
		'npcink-abilities-toolkit/resolve-media-attachment-by-url',
		'npcink-abilities-toolkit/list-users',
		'npcink-abilities-toolkit/get-menu',
		'npcink-abilities-toolkit/list-pages',
		'npcink-abilities-toolkit/get-site-operations-dashboard',
		'npcink-abilities-toolkit/build-content-inventory-fix-plan',
		'npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan',
			'npcink-abilities-toolkit/build-media-inventory-fix-plan',
			'npcink-abilities-toolkit/build-media-reference-repair-plan',
			'npcink-abilities-toolkit/build-media-settings-reference-repair-plan',
			'npcink-abilities-toolkit/build-media-optimization-plan',
			'npcink-abilities-toolkit/build-media-adoption-enhancement-plan',
			'npcink-abilities-toolkit/build-media-rename-plan',
			'npcink-abilities-toolkit/build-article-optimization-apply-plan',
				'npcink-abilities-toolkit/build-article-block-plan',
				'npcink-abilities-toolkit/build-block-theme-site-plan',
				'npcink-abilities-toolkit/build-pattern-page-plan',
			'npcink-toolbox/build-article-write-plan',
			'npcink-toolbox/build-article-batch-write-plan',
			'npcink-toolbox/build-article-media-batch-write-plan',
			'npcink-toolbox/build-image-candidate-adoption-plan',
			'npcink-toolbox/build-site-knowledge-review-plan',
			'npcink-toolbox/build-nightly-inspection-review-plan',
				'article_batch_draft_plan',
				'article_block_plan',
				'pattern_page_with_visual_asset_plan',
				'visual_acceptance_required',
				'block_native',
				'template_blocks_only',
				'surface_inspection_required',
				'image_candidate_adoption_plan',
				'docs/openclaw-article-batch-draft-plan-recipe.md',
				'docs/openclaw-article-block-plan-recipe.md',
				'docs/openclaw-pattern-page-with-visual-asset-recipe.md',
				'docs/openclaw-image-candidate-adoption-plan-recipe.md',
			'docs/openclaw-media-adoption-enhancement-plan-recipe.md',
			'npcink-toolbox/build-content-discoverability-brief',
		'npcink-abilities-toolkit/upload-media-from-url',
		'npcink-abilities-toolkit/set-post-featured-image',
		'npcink-abilities-toolkit/inspect-media-asset',
		'npcink-abilities-toolkit/optimize-media-asset',
		'npcink-abilities-toolkit/replace-media-file',
		'npcink-abilities-toolkit/restore-media-backup',
		'npcink-abilities-toolkit/adopt-cloud-media-derivative',
		'npcink-abilities-toolkit/rename-media-file',
			'npcink-abilities-toolkit/patch-post-content',
			'npcink-abilities-toolkit/update-post-blocks',
			'npcink-abilities-toolkit/update-template-blocks',
			'npcink-abilities-toolkit/upsert-template-blocks',
			'npcink-abilities-toolkit/update-template-part-blocks',
			'npcink-abilities-toolkit/patch-setting-value',
		'npcink_openclaw_adapter_patch_operations_required',
		'npcink_openclaw_adapter_blocks_required',
		'patch-post-content execution input must include operations.',
			'update-post-blocks execution input must include at least one block.',
			'update-template-blocks execution input must include at least one block.',
			'upsert-template-blocks execution input must include at least one block.',
			'update-template-part-blocks execution input must include at least one block.',
			'upsert-template-blocks execution input must include a valid slug.',
			'patch-setting-value execution input must include operations.',
			'update-post-blocks mode must be replace or append.',
			'update-template-blocks mode must be replace.',
			'upsert-template-blocks mode must be replace.',
			'update-template-part-blocks mode must be replace.',
		'npcink_openclaw_adapter_setting_target_type_invalid',
		'patch-setting-value target_type must be option or theme_mod.',
		'npcink_openclaw_adapter_derivative_artifact_required',
		'derivative_artifact',
		'npcink_openclaw_adapter_backup_id_required',
		'restore-media-backup execution input must include backup_id.',
		'restore-media-backup target_conflict_mode must be fail or overwrite.',
		"'url', 'title', 'file_name'",
		"'expected_derivative_mime_type', 'expected_storage_provider', 'expected_storage_adapter'",
		'expected_storage_provider',
		'expected_storage_adapter',
		'storage_preflight',
		'expected_content_reference_post_ids',
		'expected_content_reference_post_count',
			'expected_content_reference_replacement_count',
			'media_details_input',
			'npcink_cloud_addon_build_media_derivative_optimization_payload',
			'optimization_payload_helper_available',
			'preferred_core_route',
			'legacy_derivative_proposal_payload_available',
			'ability_guard',
			'missing_capability_behavior',
			'surface_plan_ability_unavailable_do_not_split_into_two_proposals',
			'adopt-cloud-media-derivative execution input must include derivative_artifact evidence.',
			'rename-media-file execution input must include target_file_name.',
			'rename-media-file conflict_mode must be fail or unique.',
			'rename-media-file execution input must target an existing attachment.',
			'expected_current_md5',
			'expected_current_sha256',
			'target_file_name',
			'npcink_openclaw_adapter_target_file_name_required',
			'upload-media-from-url execution input must include url.',
			'set-post-featured-image execution input must include post_id.',
			'set-post-featured-image execution input must include attachment_id or media_url.',
		'derivative_relative_file',
		'backup_id',
	) as $required
	) {
		if ( in_array( $required, maa_adapter_removed_surface_texts(), true ) ) {
			continue;
		}
		maa_adapter_assert( false !== strpos( $controller_contract, $required ), 'Controller contract contains required text: ' . $required );
	}

foreach (
	array(
		'approval_proxy_disabled',
		'npcink_openclaw_adapter_approval_proxy_disabled',
		'approval_proxy_enabled',
		'Approval disabled stub',
		'Reject disabled stub',
		'Direct approve/reject proxy routes are disabled',
	) as $removed_trialware_signal
) {
	maa_adapter_assert( false === strpos( $controller_contract, $removed_trialware_signal ), 'Controller release surface removes locked-feature signal: ' . $removed_trialware_signal );
}
maa_adapter_assert( false === strpos( $controller, "'/proposals/(?P<proposal_id>[A-Za-z0-9_-]+)/approve'," ), 'Controller does not register standalone approve route.' );
maa_adapter_assert( false === strpos( $controller, "'/proposals/(?P<proposal_id>[A-Za-z0-9_-]+)/reject'," ), 'Controller does not register standalone reject route.' );
maa_adapter_assert( false === strpos( $controller, "'POST /proposals/{proposal_id}/approve' =>" ), 'Help purpose map does not include standalone approve route.' );
maa_adapter_assert( false === strpos( $controller, "'POST /proposals/{proposal_id}/reject' =>" ), 'Help purpose map does not include standalone reject route.' );
	maa_adapter_assert( false === strpos( $controller, "'/ai-provider-log-correlation-smoke'" ), 'Controller does not register provider log correlation smoke route.' );
$key_revoke_route = substr( $controller, (int) strpos( $controller, "'/connection/key-pairs/(?P<key_id>mk_[A-Za-z0-9_-]+)'" ), 360 );
maa_adapter_assert( false !== strpos( $key_revoke_route, "array( \$this, 'can_use_admin_session' )" ), 'Client key revoke route requires administrator session auth.' );
maa_adapter_assert( false === strpos( $key_revoke_route, "array( \$this, 'can_use_adapter' )" ), 'Client key revoke route is not available through signed adapter clients.' );
	$client_key_auth = substr( $controller, (int) strpos( $controller, 'private function authenticate_signed_request' ), 2600 );
	maa_adapter_assert( false !== strpos( $client_key_auth, 'should_update_client_key_last_used' ), 'Signed request auth throttles last-used option writes.' );
	maa_adapter_assert( false !== strpos( $client_key_auth, 'current_signed_client_fingerprint' ), 'Signed request auth records the current client fingerprint.' );
	$upstream_dispatch = substr( $controller, (int) strpos( $controller, 'private function dispatch_upstream( string' ), 1600 );
	maa_adapter_assert( false !== strpos( $upstream_dispatch, 'x-npcink-adapter-signed-client-fingerprint' ), 'Adapter forwards signed client fingerprint to Core app-token requests.' );
	maa_adapter_assert( false !== strpos( $upstream_dispatch, 'x-npcink-adapter-client-key-fingerprint' ), 'Adapter forwards compatible client key fingerprint alias to Core app-token requests.' );
	maa_adapter_assert( false !== strpos( $controller, "'npcink_conn_'" ), 'Device pairing creates Npcink-branded connection ids.' );
	maa_adapter_assert( false === strpos( $controller, "'mag_conn_'" ), 'Device pairing no longer creates Magick-branded connection ids.' );
	$client_key_scope = substr( $controller, (int) strpos( $controller, 'private function client_key_scope_allows_request' ), 1400 );
			maa_adapter_assert( false === strpos( $client_key_scope, "'/media-derivative-runs'" ), 'Client key scopes no longer special-case media derivative runs.' );
			maa_adapter_assert( false === strpos( $client_key_scope, "'/media-derivative-proposal-payload'" ), 'Client key scopes no longer special-case media derivative proposal payloads.' );
		maa_adapter_assert( false !== strpos( $client_key_scope, 'client_key_route_requires_execute_scope' ), 'Client key scopes route final execution requests through execute scope.' );
		maa_adapter_assert( false !== strpos( $client_key_scope, "'npcink.execute'" ), 'Client key scopes require npcink.execute for final write routes.' );
		maa_adapter_assert( false !== strpos( $client_key_scope, "'magick.execute'" ), 'Client key scopes preserve legacy Magick execute scope compatibility.' );
		maa_adapter_assert( false !== strpos( $client_key_scope, "'magick.status'" ), 'Client key scopes preserve legacy Magick status scope compatibility.' );
		maa_adapter_assert( false !== strpos( $client_key_scope, "'magick.propose'" ), 'Client key scopes preserve legacy Magick propose scope compatibility.' );
		maa_adapter_assert( false !== strpos( $client_key_scope, "'magick.read'" ), 'Client key scopes preserve legacy Magick read scope compatibility.' );
	$execute_scope_routes = substr( $controller, (int) strpos( $controller, 'private function client_key_route_requires_execute_scope' ), 700 );
	maa_adapter_assert( false !== strpos( $execute_scope_routes, "'/commit-preflight'" ), 'Client key execute scope covers commit-preflight handoff consumption.' );
	maa_adapter_assert( false !== strpos( $execute_scope_routes, "'/approve-and-execute'" ), 'Client key execute scope covers approve-and-execute.' );
	$requested_scopes = substr( $controller, (int) strpos( $controller, 'private function connection_requested_scopes' ), 900 );
	maa_adapter_assert( false !== strpos( $requested_scopes, "'npcink.execute' => true" ), 'Device pairing can explicitly request npcink.execute.' );
	maa_adapter_assert( false !== strpos( $requested_scopes, "\$default_scopes = array( 'npcink.read', 'npcink.propose', 'npcink.status' );" ), 'Device pairing defaults do not silently grant execute scope.' );
	$plan_batch_metadata = substr( $controller, (int) strpos( $controller, 'private function normalize_plan_batch_metadata' ), 1400 );
maa_adapter_assert( false !== strpos( $plan_batch_metadata, "\$plan['proposal_mode']  = 'batch';" ), 'Adapter makes dependent plan batches explicit before Core from-plan forwarding.' );
maa_adapter_assert( false !== strpos( $plan_batch_metadata, "\$plan['batch_approval'] = true;" ), 'Adapter makes dependent plan batch approval explicit before Core from-plan forwarding.' );
$plan_write_input_validation = substr( $controller, (int) strpos( $controller, 'private function validate_plan_write_action_inputs' ), 2600 );
maa_adapter_assert( false !== strpos( $plan_write_input_validation, "\$proposal_ready = array_key_exists( 'proposal_ready', \$raw_action )" ) && false !== strpos( $plan_write_input_validation, "\$requires_input = array_values( array_map( 'sanitize_key', (array) ( \$raw_action['requires_input'] ?? array() ) ) )" ) && false !== strpos( $plan_write_input_validation, 'if ( ! $proposal_ready && ! empty( $requires_input ) )' ), 'Adapter forwards requires-input blocked plan actions to Core instead of requiring executable proposal input locally.' );
maa_adapter_assert( false !== strpos( $controller, 'min( self::MAX_PROPOSAL_LIST_LIMIT, max( 1, absint' ), 'Adapter list routes clamp caller supplied limits.' );
maa_adapter_assert( false === strpos( $controller, 'HTTP_USER_AGENT' ), 'Public pairing rate limit is not weakened by caller-controlled user agents.' );
maa_adapter_assert( false !== strpos( $controller, "approve_device_pairing( string \$user_code, string \$admin_label = '' )" ), 'Controller accepts an administrator label during device pairing approval.' );
maa_adapter_assert( false !== strpos( $controller, '$admin_label = $this->bounded_text_field( $admin_label, 80 );' ), 'Controller bounds administrator device labels before storage.' );
maa_adapter_assert( false !== strpos( $controller, "'admin_label'   => \$admin_label" ), 'Controller stores administrator device labels with key-pair records.' );
maa_adapter_assert( false !== strpos( $controller, "'admin_label'   => (string) ( \$record['admin_label'] ?? '' )" ), 'Controller exposes administrator device labels to the current administrator key-pair view.' );
maa_adapter_assert( false === strpos( $controller, '$supported_execute_ability_ids' ), 'Controller derives execute supported profiles from execution profiles.' );
maa_adapter_assert( false === strpos( $controller, 'include_log_tail' ), 'Adapter does not implement old include_log_tail compatibility.' );
maa_adapter_assert( false === strpos( $controller, 'include_error_log' ), 'Adapter does not use old include_error_log diagnostics input.' );
maa_adapter_assert( false === strpos( $controller, 'can_approve_proposals' ), 'Adapter does not call Core proposal approval permission path.' );
maa_adapter_assert( false === strpos( $controller, 'can_reject_proposals' ), 'Adapter does not call Core proposal rejection permission path.' );
maa_adapter_assert( false === strpos( $controller, 'approve_proposal' ), 'Adapter does not call Core proposal approval callback.' );
maa_adapter_assert( false === strpos( $controller, 'reject_proposal' ), 'Adapter does not call Core proposal rejection callback.' );
maa_adapter_assert( false === strpos( $controller, 'proposals:approve' ), 'Adapter does not request proposal approval scope.' );
maa_adapter_assert( false === strpos( $controller, 'proposals:reject' ), 'Adapter does not request proposal rejection scope.' );
maa_adapter_assert( false === strpos( $controller, "'replace_original'" ), 'Adapter optimize-media-asset profile does not allow original replacement.' );
maa_adapter_assert( false === strpos( $controller, "'replacement_url'" ), 'Adapter replace-media-file profile does not allow external replacement URLs.' );
maa_adapter_assert( false === strpos( $controller, "'mode', 'derivative_relative_file'" ), 'Adapter replace-media-file profile does not allow legacy restore modes.' );
$safe_observability = substr( $controller, (int) strpos( $controller, 'private function safe_observability_context' ) );
foreach ( array( "'input'", "'plan'", "'preview'", "'response'", "'upstream_data'", "'authorization'", "'token'", "'secret'", "'prompt'", "'content'" ) as $forbidden ) {
	maa_adapter_assert( false === strpos( $safe_observability, $forbidden ), 'Safe observability context excludes raw field: ' . $forbidden );
}
$observability = maa_adapter_read( $root . '/includes/Observability.php' );
foreach ( array( 'sanitize_payload', "'event_id'", "'adapter_request_id'", "'status_code'", "'executed_count'", "'failed_count'" ) as $required ) {
	maa_adapter_assert( false !== strpos( $observability, $required ), 'Observability bridge keeps supported field: ' . $required );
}
foreach ( array( "'input'", "'plan'", "'preview'", "'response'", "'upstream_data'", "'authorization'", "'token'", "'secret'", "'prompt'", "'content'" ) as $forbidden ) {
	maa_adapter_assert( false === strpos( $observability, $forbidden ), 'Observability bridge excludes raw field: ' . $forbidden );
}

$plugin = maa_adapter_read( $root . '/includes/Plugin.php' );
foreach (
	array(
		'rest_request_before_callbacks',
		'rest_request_after_callbacks',
		'capture_adapter_dispatch_start',
		'emit_adapter_dispatch_event',
		'adapter.openclaw.dispatch.completed',
		'adapter.openclaw.dispatch.failed',
		'adapter.dispatch_failed',
		'admin_menu',
		'plugin_action_links_',
		'filter_plugin_action_links',
		'admin.php?page=npcink-ai-client-adapter',
		'admin_post_npcink_openclaw_adapter_create_openclaw_password',
		'register_admin_page',
		'handle_create_openclaw_password',
		'Admin\\Connection_Page',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $plugin, $required ), 'Plugin registers admin page text: ' . $required );
}

$connection_page = maa_adapter_read( $root . '/includes/Admin/Connection_Page.php' );
$admin_css       = maa_adapter_read( $root . '/assets/admin.css' );
$admin_js        = maa_adapter_read( $root . '/assets/admin.js' );
maa_adapter_assert( false !== strpos( $connection_page, "const PARENT_MENU_SLUG = 'npcink-ai';" ), 'Connection page targets the shared Npcink AI parent menu slug.' );
foreach (
	array(
		'Npcink AI Client Adapter',
		'Connect this site to local AI clients.',
		'Connect this WordPress site to OpenClaw or other local AI clients.',
		'Adapter',
		'AI Client Connection Created',
		'Secure key pairing',
		'maa-heading-badge',
		'Fallback: WordPress Application Password connection',
		'Recommended path: pair a local signed key',
		'Copy connect command',
		'maa-action-hint',
		'Active devices',
		'maa-disclosure-copy',
		'maa-disclosure-icon',
		'Manage devices',
		'data-maa-open-target="maa-authorized-devices"',
		'Revoke a device when it is no longer used or was approved by mistake',
		'Create Application Password connection',
		'add_submenu_page',
		'PARENT_MENU_SLUG',
		'WP_Application_Passwords::create_new_application_password',
			'Include LocalWP TLS setting',
			'LocalWP TLS option',
			'Use only for localhost or .local testing',
			'APPLICATION_PASSWORD_FALLBACK_CONFIRM_FIELD',
			'I understand this fallback creates a WordPress Application Password',
			'NPCINK_OPENCLAW_ADAPTER_DISABLE_APPLICATION_PASSWORD_FALLBACK',
			'application_password_fallback_enabled',
			'application_password_unavailable_message',
				'Site',
			'proposal_lookup',
			'render_proposal_lookup_result',
		'Open in Core',
		'Copy status URL',
		'Copy execute URL',
		'proposal_next_step_text',
		"DATETIME_DISPLAY_FORMAT = 'Y-m-d H:i:s'",
			'display_datetime',
			'wp_date( self::DATETIME_DISPLAY_FORMAT, $timestamp )',
			'$this->display_datetime( $created )',
			'$this->display_datetime( $updated )',
			"\$this->display_datetime( (string) ( \$record['last_used_at'] ?? '' ) )",
			'key_pair_summary_text',
		'content_discoverability_suggestions',
		'ai_article_draft_with_discoverability',
		'pattern_page_research_brief',
		'content-discoverability-validation',
		'content-discoverability-context',
		'content-discoverability-brief',
		'article-writing-pack',
		'landing_page_research_brief',
		'competitor_research',
			'Return suggestions only; do not write SEO meta',
			'primary entrypoint is content-discoverability-brief',
			'do not copy reference site text, images, CSS',
			'Use article-writing-pack only for broad article requests',
		'openclaw_connection_manifest_text',
		'WorkBuddy setup',
		'Copy WorkBuddy setup',
		'workbuddy_handoff_text',
		'Npcink AI Client Adapter WorkBuddy connection',
		'wordpress_application_password',
		'connection_id',
		'local-wordpress',
		'wordpress_application_password',
		'password_uuid',
		'Secret must be stored through the AI client credential store or dedicated secret field',
			'Proposal detail',
		'NPCINK_OPENCLAW_ADAPTER_APPLICATION_PASSWORD',
		'NPCINK_OPENCLAW_ADAPTER_APPLICATION_PASSWORD=<store-in-openclaw-secret-vault>',
			'Copy this Application Password now.',
				'One-time Application Password',
				'Copy Application Password',
				'Show non-secret manifest',
				'Show env placeholder',
				'Show WorkBuddy setup',
				'Show full handoff text',
				'Copy full handoff text',
				'Paste it only into the AI client dedicated secret field',
				'GET /help',
				'GET /proposals/{proposal_id}',
				'approve-and-execute',
			'Adapter execution profiles currently support npcink-abilities-toolkit/trash-post, npcink-abilities-toolkit/create-draft, npcink-abilities-toolkit/update-post, npcink-abilities-toolkit/patch-post-content, npcink-abilities-toolkit/update-post-blocks, npcink-abilities-toolkit/update-template-blocks, npcink-abilities-toolkit/upsert-template-blocks, npcink-abilities-toolkit/update-template-part-blocks, npcink-abilities-toolkit/patch-setting-value, npcink-abilities-toolkit/set-post-seo-meta, npcink-abilities-toolkit/set-post-slug, npcink-abilities-toolkit/set-post-terms, npcink-abilities-toolkit/delete-term, npcink-abilities-toolkit/update-media-details, npcink-abilities-toolkit/upload-media-from-url, npcink-abilities-toolkit/set-post-featured-image, npcink-abilities-toolkit/optimize-media-asset, npcink-abilities-toolkit/replace-media-file, npcink-abilities-toolkit/restore-media-backup, npcink-abilities-toolkit/adopt-cloud-media-derivative, npcink-abilities-toolkit/rename-media-file, npcink-abilities-toolkit/delete-media-permanently, npcink-abilities-toolkit/reply-comment, npcink-abilities-toolkit/trash-comment, and npcink-abilities-toolkit/approve-comment',
		'Adapter execute routes are final write paths and normalize ability input to dry_run=false and commit=true',
		'for dry-run-only verification, stop at commit-preflight and do not call execute',
		'Failure code handling',
		'npcink_openclaw_adapter_preflight_item_blocked',
		'AI Request Logs context',
		'wpai_request_log_context',
		'log_context',
		'proposal_id',
		'correlation_id',
			'core_proxy_execute=false',
			'commit_execution=false',
			'<client-secret-field-value>',
			'Copy connect command',
			'local_cli_new_session_opener_text',
			'already paired local Npcink AI Client Adapter profile',
		'Read client_policy from /help and treat it as machine-readable policy',
		'read_policy=core_read_authorization_required',
		'read-request create --profile=local',
		'read-request status --profile=local',
		'read-ability --profile=local',
		'local_cli_read_request_create_template',
		'local_cli_read_request_status_template',
		'local_cli_read_ability_template',
			'local_cli_setup_text',
		'render_key_pair_clients_table',
		'render_key_pair_clients_table_rows',
		'maa-device-table',
		'maa-device-status',
		'maa-revoke-button',
		'Revoked devices',
		'Device ID',
		'local_cli_connect_command',
		'local_cli_status_command',
		'LOCAL_CLI_PACKAGE',
		'@npcink/openclaw-adapter-cli@0.2.0',
		'--intent=preflight',
		'--intent=commit',
		'final execute routes require --intent=commit',
			'Do not read, cat, print, summarize, or copy the local keypair profile file',
				'REVOKE_KEY_ACTION',
				'admin_label',
				'Device note',
				'Example: Muze MacBook or office OpenClaw',
				'Optional administrator-only label for later management',
				'It is not used for authentication or authorization',
				'maa-pairing-form',
				'Revoke this device? It must pair again before it can connect.',
				'Revoke authorization',
				'Npcink client approved',
			'Connection approved.',
			'Return to the terminal or local AI client',
			'private key was never sent to WordPress',
			'If you did not start this pairing, revoke this client',
			'Manage paired clients',
			'Device reported by client',
			'Used pairing code',
			'Approved access',
			'Read approved Adapter and WordPress Abilities API routes.',
			'Create Core-governed proposals for reviewed writes.',
			'This approval does not grant direct WordPress write, approval, publish',
			'Diagnostic details',
			'IDs and fingerprints for support; these are not client setup secrets.',
			'Client key record ID',
			'Connection rejected.',
			'Npcink client rejected',
			'PAIR_MENU_SLUG',
			'result_status',
	) as $required
	) {
		if ( in_array( $required, maa_adapter_removed_admin_connection_texts(), true ) ) {
			continue;
		}
		maa_adapter_assert( false !== strpos( $connection_page, $required ), 'Connection page contains required text: ' . $required );
	}
	foreach ( maa_adapter_removed_admin_connection_texts() as $removed_admin_text ) {
		maa_adapter_assert( false === strpos( $connection_page, $removed_admin_text ), 'Connection page removes verbose/non-core admin text: ' . $removed_admin_text );
	}
$render_start  = strpos( $connection_page, 'public function render(): void' );
$render_end    = strpos( $connection_page, 'public function render_pairing_page(): void' );
$render_source = false !== $render_start && false !== $render_end ? substr( $connection_page, $render_start, $render_end - $render_start ) : '';
maa_adapter_assert( '' !== $render_source, 'Connection page render source is extractable for default UI checks.' );
foreach (
	array(
		'maa-tabs',
		'data-maa-tab-target',
		'id="maa-advanced"',
		'Continue proposal',
		'Advanced details',
		'Connection values',
		'Connect command',
		'Status check',
		'Copy status command',
		'Client env placeholder',
		'Copy env placeholder',
		'Copy manifest URL',
		'Connection manifest:',
		'Copy Adapter URL',
	) as $removed
) {
	maa_adapter_assert( false === strpos( $render_source, $removed ), 'Default connection UI omits low-frequency surface: ' . $removed );
}
foreach (
	array(
		'maa-device-manager',
		'maa-device-table',
		'maa-device-status-active',
		'maa-device-status-revoked',
		'maa-revoke-button',
		'maa-revoked-devices',
		'maa-disclosure-copy',
		'maa-disclosure-icon::before',
		'maa-backup-connection form',
		'max-width: 900px',
		'margin: 18px 0 16px 20px',
		'padding: 0',
		'.maa-summary .maa-label',
		'margin-bottom: 0',
		'maa-action-hint::before',
		'border-left: 3px solid #72aee6',
		'vertical-align: middle',
	) as $required_css
) {
	maa_adapter_assert( false !== strpos( $admin_css, $required_css ), 'Admin CSS keeps device management table polish: ' . $required_css );
}
foreach (
	array(
		'[data-maa-open-target]',
		'closeSiblingDisclosures',
		".maa-method-card details.maa-inline-disclosure",
		'details.open = false',
		'target.open = true',
		'details.addEventListener',
		'scrollIntoView',
	) as $required_js
) {
	maa_adapter_assert( false !== strpos( $admin_js, $required_js ), 'Admin JavaScript keeps connection disclosures mutually exclusive: ' . $required_js );
}
foreach (
	array(
		'Adapter effective status',
		'Blocked reason',
		'Media readiness',
		'Execution verification',
		'View readiness details',
		'View verification details',
		'Review summary',
		'readiness_summary_text',
		'verification_summary_text',
		'json_summary',
		'content_reference_actual_replacement_count',
		'post_reference_count',
		'post_reference_old_urls_absent',
		'post_reference_new_urls_present',
		'old URLs absent',
		'new URLs present',
		'backup_available',
		'rollback_available',
		'nullable_boolean_label',
	) as $required
	) {
		maa_adapter_assert( false === strpos( $connection_page, $required ), 'Connection page removes Adapter proposal lookup field: ' . $required );
	}
$env_password_key       = 'NPCINK_OPENCLAW_ADAPTER_APPLICATION_PASSWORD=';
$php_password_variable  = '$password';
maa_adapter_assert( false === strpos( $connection_page, $env_password_key . "' . " . $php_password_variable ), 'Connection page env text does not interpolate the real secret.' );
maa_adapter_assert( false === strpos( $connection_page, '$this->openclaw_env_text( $username, $password,' ), 'Connection page does not pass the real Application Password into env text.' );
maa_adapter_assert( false === strpos( $connection_page, '$this->openclaw_created_handoff_text( $username, $password,' ), 'Connection page does not pass the real secret into copied handoff.' );
maa_adapter_assert( false === strpos( $connection_page, '$this->workbuddy_handoff_text( $username, $password,' ), 'Connection page does not pass the real secret into WorkBuddy setup.' );
maa_adapter_assert( false === strpos( $connection_page, "echo esc_html( '' !== \$created ? \$created" ), 'Connection page does not output raw Core proposal creation time.' );
maa_adapter_assert( false === strpos( $connection_page, "echo esc_html( '' !== \$updated ? \$updated" ), 'Connection page does not output raw Core proposal update time.' );
maa_adapter_assert( false === strpos( $connection_page, "echo esc_html( (string) ( \$record['last_used_at'] ?? '' )" ), 'Connection page does not output raw key-pair last-used time.' );
maa_adapter_assert( false !== strpos( $connection_page, 'openclaw_connection_manifest_text( string $username, string $password_uuid )' ), 'Connection manifest receives only username and password UUID.' );
maa_adapter_assert( false === strpos( $connection_page, 'openclaw_created_handoff_text(' ), 'Connection page removes verbose created handoff text builder.' );
maa_adapter_assert( false === strpos( $connection_page, 'workbuddy_handoff_text(' ), 'Connection page removes WorkBuddy setup text builder.' );
maa_adapter_assert( false !== strpos( $connection_page, "admin_url( 'admin.php?page=' . self::MENU_SLUG )" ), 'Created handoff return link targets the Adapter admin page explicitly.' );
maa_adapter_assert( false === strpos( $connection_page, 'menu_page_url( self::MENU_SLUG, false )' ), 'Created handoff return link does not resolve through current admin-post context.' );
maa_adapter_assert( false !== strpos( $connection_page, "const MENU_SLUG        = 'npcink-ai-client-adapter';" ), 'Connection page uses the canonical Adapter admin slug.' );
maa_adapter_assert( false !== strpos( $connection_page, "__( 'Npcink AI Client Adapter', 'npcink-ai-client-adapter' ),\n\t\t\t__( 'Adapter', 'npcink-ai-client-adapter' )," ), 'Connection page registers the requested page and menu titles.' );
maa_adapter_assert( false !== strpos( $connection_page, "esc_html( 'Npcink AI Client Adapter' )" ), 'Connection page uses the fixed product name for the primary admin heading.' );
maa_adapter_assert( false === strpos( $connection_page, 'Developer route details and local testing notes are documented' ), 'Connection page default view does not show developer route notes.' );
maa_adapter_assert( false === strpos( $connection_page, '<code>docs/admin-developer-reference.md</code>' ), 'Connection page default view keeps developer reference out of the admin surface.' );
maa_adapter_assert( false === strpos( $connection_page, 'Connect AI clients through the Adapter surface.' ), 'Connection page avoids the old Adapter-surface connection wording.' );
maa_adapter_assert( false === strpos( $connection_page, 'npcink-openclaw-adapter-openclaw' ), 'Connection page does not use the old OpenClaw-specific admin slug.' );
maa_adapter_assert( false !== strpos( $connection_page, "'npcink-cloud-addon'" ), 'Connection page overview links to the canonical Cloud Addon slug.' );
maa_adapter_assert( false !== strpos( $connection_page, "__( 'Cloud Addon', 'npcink-ai-client-adapter' )" ), 'Connection page overview labels the Cloud Addon surface.' );
maa_adapter_assert( false !== strpos( $connection_page, "'npcink-workflow-toolbox'" ), 'Connection page overview links to the canonical Toolbox slug.' );
maa_adapter_assert( false !== strpos( $connection_page, "__( 'Toolbox', 'npcink-ai-client-adapter' )" ), 'Connection page overview labels the Toolbox surface.' );

$admin_surface_standard = maa_adapter_read( $root . '/docs/admin-surface-standard.md' );
foreach (
	array(
			'OpenClaw connection surface',
			'Secure key pairing',
			'Fallback: WordPress Application Password connection',
			'Copy connect command',
			'Manage devices',
			'active signed key-pair devices',
			'low-level key-pair client diagnostics',
			'Proposal ID status lookup',
			'Created Handoff',
			'Copy Application Password',
			'duplicate Core review queue',
			'POST /run-read-ability` examples for underlying ability ids',
			'docs/admin-developer-reference.md',
			'Core proposal approval tables',
			'ability definitions',
			'Cloud Base URL/API key',
			'workflow recipe registry',
			'execution profile allowlist ownership',
			'AI Request Logs/Core audit merge',
			'npcink-workflow-toolbox',
			'Time Display',
			'WordPress site timezone',
			'Y-m-d H:i:s',
		'Do not print raw UTC strings',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $admin_surface_standard, $required ), 'Admin surface standard documents Adapter page boundary: ' . $required );
}

$admin_developer_reference = maa_adapter_read( $root . '/docs/admin-developer-reference.md' );
foreach (
	array(
		'Adapter Developer Reference',
		'Connection Routes',
		'Proposal Routes',
		'Read Shortcuts',
		'AI Request Logs Correlation',
		'Admin Boundary',
		'secure signed key-pair connection',
		'simple WordPress Application Password connection',
		'core_proxy_execute=false',
		'commit_execution=false',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $admin_developer_reference, $required ), 'Admin developer reference carries removed advanced detail: ' . $required );
}

$cloud_boundary = maa_adapter_read( $root . '/docs/cloud-connector-boundary.md' );
foreach (
	array(
			'Adapter is not the WordPress-to-Cloud connector',
			'Cloud Addon is the WordPress-side Cloud connector',
			'npcink_cloud_addon_runtime_client()',
			'npcink_cloud_addon_is_configured()',
			'proposal-specific readiness checks and approved local adoption only',
			'Cloud Addon and Cloud tooling own run creation',
			'Adapter must not expose a parallel `/cloud/*` REST surface',
		'Adapter-owned Cloud settings, signing clients, or `/cloud/*` routes',
		'Adapter does not register `/cloud/*` routes',
		'Adapter does not store Cloud API keys or sign Cloud requests',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $cloud_boundary, $required ), 'Cloud boundary keeps Adapter behind Cloud Addon: ' . $required );
}
foreach ( array( '/cloud/health', '/cloud/runs', '/cloud/analysis', '/cloud/stats' ) as $forbidden ) {
	maa_adapter_assert( false === strpos( $controller, $forbidden ), 'Controller does not register Adapter Cloud route: ' . $forbidden );
}
maa_adapter_assert( false === strpos( $controller, 'X-Npcink-Signature' ), 'Adapter controller does not implement Cloud request signing.' );
maa_adapter_assert( false === strpos( $cloud_boundary, 'Adapter is the WordPress-to-Cloud connector.' ), 'Cloud boundary no longer assigns Cloud connector ownership to Adapter.' );

$readme = maa_adapter_read( $root . '/README.md' );
$plugin_readme = maa_adapter_read( $root . '/readme.txt' );
foreach (
	array(
		'thin AI client channel plugin',
		'read Npcink Governance Core capability guidance',
		'run approved direct-read abilities through WordPress Abilities API',
		'create Core proposals',
		'Cloud runtime access belongs to the standalone `npcink-cloud-addon`',
		'Adapter must not add its own Cloud settings, signing client, or `/cloud/*`',
		'The current Adapter plugin header declares `Requires Plugins:',
		'npcink-abilities-toolkit, npcink-governance-core`',
		'Adapter does not expose direct-read shortcut routes, workflow recipe routes',
		'Cloud/media derivative façade routes',
		'Diagnostic reads should call `POST /run-read-ability`',
		'npcink-abilities-toolkit/wp-ops-diagnostics-detail',
		'For media metadata optimization, call `POST /run-read-ability`',
		'For Cloud-generated media derivatives, Cloud transport and run/result truth',
			'Adapter emits local metadata-only events through',
			'Adapter performs identifier passthrough only',
		'npcink_openclaw_adapter_observability_event',
		'canonical Adapter event kinds are',
		'adapter.openclaw.dispatch.completed',
		'adapter.openclaw.dispatch.failed',
		'They must not include raw OpenClaw requests',
		'without turning Adapter into a proposal queue',
			'read-only proposal status examples for developer integration',
			'Adapter executes only explicit supported execution',
			'does not mutate Adapter-side approval state',
		'does not define abilities',
		'execute final write mutations',
		'Npcink -> Adapter',
		'docs/openclaw-quickstart.md',
		'docs/openclaw-connection-model-notes.md',
		'docs/openclaw-consumer-acceptance.md',
		'docs/openclaw-batch-execution-policy.md',
		'docs/external-ai-client-contract.md',
		'Npcink AI Suite Distribution Contract',
		'npcink_openclaw_adapter_missing_dependency',
		'input.write_actions[]',
		'execution profile registry',
		'Capability discovery may show more proposal-required abilities',
		'The registry stays in Adapter as post-Core execution policy',
		'it must not be extended through filters, options',
		'database rows, remote configuration, wildcards',
		'Each profile is an explicit post-Core policy entry, not a generic executor',
		'validate required ids/enums/sizes',
		'$outputs.create-draft.post_id',
		'only its hash',
			'Application Password secret-field',
			'non-secret connection manifest',
			'copyable WorkBuddy setup block',
			'key-pair device pairing MVP',
			'docs/keypair-device-pairing-contract.md',
			'does not keep root-level `tools/` compatibility',
			'@npcink/openclaw-adapter-cli',
			'Final Adapter write routes',
			'--intent=preflight',
			'--intent=commit',
			'dry_run=true',
			'commit=false',
			'commit_execution=false',
			'Public Key Device Pairing',
			'AI clients should connect through Adapter',
			'does not rely on controlling any specific external AI client',
			'docs/local-ai-client-policy.md',
			'GET /wp-json/npcink-openclaw-adapter/v1/help',
		'Adapter does not expose a provider/model smoke route',
			'include_log_contents',
			'tail_lines',
			'since_minutes',
			'not explicitly requested',
			'error_log.tail_entries',
			'error_log.summary',
			'fatal_count',
			'deprecated_count',
			'summary_source',
			'error_log.summary.by_severity',
		'GET /wp-json/npcink-openclaw-adapter/v1/proposals',
		'GET /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}',
		'POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan',
		'POST /wp-json/npcink-openclaw-adapter/v1/execute-approved-proposal',
		'POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/execute',
		'POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/approve-and-execute',
		'POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/approve',
		'POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/reject',
		'approved proposal execution',
		'approve-and-execute',
		'npcink-abilities-toolkit/trash-post',
		'npcink-abilities-toolkit/create-draft',
		'npcink-abilities-toolkit/update-post',
		'npcink-abilities-toolkit/patch-post-content',
			'npcink-abilities-toolkit/update-post-blocks',
			'npcink-abilities-toolkit/get-post-blocks',
			'npcink-abilities-toolkit/get-template-blocks',
			'npcink-abilities-toolkit/get-template-part-blocks',
			'bounded post-execution readback',
			'readback failure is recorded as verification metadata',
			'npcink-abilities-toolkit/patch-setting-value',
			'npcink-abilities-toolkit/set-post-seo-meta',
		'npcink-abilities-toolkit/set-post-slug',
		'npcink-abilities-toolkit/set-post-terms',
		'npcink-abilities-toolkit/delete-term',
		'npcink-abilities-toolkit/update-media-details',
		'npcink-abilities-toolkit/optimize-media-asset',
		'npcink-abilities-toolkit/replace-media-file',
		'npcink-abilities-toolkit/restore-media-backup',
		'npcink-abilities-toolkit/adopt-cloud-media-derivative',
		'npcink-abilities-toolkit/rename-media-file',
		'npcink-abilities-toolkit/delete-media-permanently',
		'npcink-abilities-toolkit/reply-comment',
		'npcink-abilities-toolkit/trash-comment',
			'npcink-abilities-toolkit/approve-comment',
			'NPCINK_OPENCLAW_ADAPTER_CORE_APP_TOKEN',
			'npcink_openclaw_adapter_core_app_token',
			'approval_surface=npcink_governance_core_admin',
		'POST /wp-json/npcink-openclaw-adapter/v1/run-read-ability',
		'proposals:read',
		'audit_timeline',
		'Core Governance Audit is the governance log',
			'adapter_request_id',
			'adapter_route',
			'governance_source=npcink-governance-core',
		'npcink_governance_core.proposal_id',
		'npcink_governance_core.correlation_id',
		'governance_mode=direct_read',
		'governance_mode=proposal_required',
		'npcink-abilities-toolkit/build-content-inventory-fix-plan',
		'npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan',
			'npcink-abilities-toolkit/build-media-inventory-fix-plan',
			'npcink-abilities-toolkit/build-media-reference-repair-plan',
			'npcink-abilities-toolkit/build-media-settings-reference-repair-plan',
			'npcink-abilities-toolkit/build-media-optimization-plan',
			'npcink-abilities-toolkit/build-media-adoption-preflight-summary',
			'npcink-abilities-toolkit/build-media-rename-plan',
			'npcink-abilities-toolkit/build-article-optimization-apply-plan',
				'npcink-abilities-toolkit/build-article-block-plan',
				'npcink-abilities-toolkit/build-block-theme-site-plan',
				'npcink-abilities-toolkit/build-pattern-page-plan',
			'npcink-toolbox/build-article-write-plan',
			'npcink-toolbox/build-article-batch-write-plan',
			'npcink-toolbox/build-article-media-batch-write-plan',
			'npcink-toolbox/build-image-candidate-adoption-plan',
			'npcink-toolbox/build-site-knowledge-review-plan',
			'npcink-toolbox/build-nightly-inspection-review-plan',
			'npcink-toolbox/build-ai-article-writing-pack',
			'Adapter does not expose direct-read shortcut routes',
						'docs/openclaw-block-theme-site-builder-recipe.md',
						'docs/openclaw-content-intent-router-contract.md',
						'docs/openclaw-gutenberg-design-system.md',
						'docs/openclaw-gutenberg-content-intent-routing-baseline.md',
						'docs/openclaw-site-edit-router-contract.md',
		'Cloud-backed image source recommender',
		'hosted AI image generation only when no reviewable recommendation fits',
		'crop and convert the selected candidate through the Cloud media derivative path',
		'variables.hero_media_url',
		'docs/openclaw-ai-article-writing-pack-recipe.md',
		'The primary SEO/GEO/AEO entrypoint is',
		'Adapter Contract Reuse Readiness - 2026-07-08',
		'docs/adapter-contract-reuse-readiness-2026-07-08.md',
		'Use `article-writing-pack` only for broad natural-language requests',
		'docs/openclaw-article-draft-plan-recipe.md',
			'docs/openclaw-article-batch-draft-plan-recipe.md',
			'docs/openclaw-article-media-batch-plan-recipe.md',
			'docs/openclaw-article-block-plan-recipe.md',
			'docs/openclaw-pattern-page-plan-recipe.md',
			'docs/openclaw-gutenberg-design-system.md',
			'docs/openclaw-pattern-page-research-brief-recipe.md',
			'docs/openclaw-pattern-page-with-visual-asset-recipe.md',
			'docs/openclaw-ai-image-ratio-crop-media-adoption-recipe.md',
			'docs/openclaw-image-candidate-adoption-plan-recipe.md',
			'docs/openclaw-media-adoption-enhancement-plan-recipe.md',
		'skipped_destructive_candidates',
		'write_actions',
		'Plan-to-proposal flow',
		'POST /npcink-governance-core/v1/proposals/from-plan',
		'core_proxy_execute=false',
		'commit_execution=false',
		'npcink_openclaw_adapter_core_read_authorization_required',
		'Prompt text',
		'composer plugin-check:release',
		'composer package:release',
		'composer accept:local-ai-client',
		'docs/local-ai-client-acceptance.md',
		'Local smoke and HTTP acceptance tests must register every created fixture',
		'automatic cleanup before assertions can fail',
		'negative-loop cases must not rely on final write execution',
		'complete image `src`/`alt` attributes',
		'Gutenberg-native spacing on key sections',
		'post-execution `get-post-blocks` readback verification',
		'composer visual:wp',
		'build/visual-acceptance/',
		'MAA_ADAPTER_VISUAL_ACCEPTANCE_SKIP_SMOKE=1',
		'MAA_ADAPTER_VISUAL_ACCEPTANCE_CREATE_TEMP_ADMIN=1',
		'.distignore',
		'Do not delete',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $readme, $required ), 'README contains required text: ' . $required );
}
foreach ( $execution_profile_ids as $execution_profile_id ) {
	maa_adapter_assert( false !== strpos( $readme, $execution_profile_id ), 'README documents execution profile: ' . $execution_profile_id );
	maa_adapter_assert( false !== strpos( $plugin_readme, $execution_profile_id ), 'readme.txt documents execution profile: ' . $execution_profile_id );
}
foreach (
	array(
		'GET shortcut query parameters are forwarded',
		'GET /wp-json/npcink-openclaw-adapter/v1/site-info',
		'GET /wp-json/npcink-openclaw-adapter/v1/site-summary',
		'GET /wp-json/npcink-openclaw-adapter/v1/active-plugins-detail',
		'GET /wp-json/npcink-openclaw-adapter/v1/plugin-conflict-diagnostics',
		'GET /wp-json/npcink-openclaw-adapter/v1/current-user-permissions',
		'GET /wp-json/npcink-openclaw-adapter/v1/database-info',
		'GET /wp-json/npcink-openclaw-adapter/v1/recent-error-log-tail',
		'GET /wp-json/npcink-openclaw-adapter/v1/content-discoverability-validation',
		'GET /wp-json/npcink-openclaw-adapter/v1/content-discoverability-context',
		'GET /wp-json/npcink-openclaw-adapter/v1/content-discoverability-brief',
		'GET /wp-json/npcink-openclaw-adapter/v1/article-writing-pack',
		'Content shortcuts pass query parameters',
		'a `Proposal status` lookup',
		'open the matching Core approval detail',
	) as $removed_readme_text
) {
	maa_adapter_assert( false === strpos( $readme, $removed_readme_text ), 'README omits removed positioning text: ' . $removed_readme_text );
}
$current_boundary_docs = array(
	'AGENTS.md' => maa_adapter_read( $root . '/AGENTS.md' ),
	'README.md' => $readme,
	'packages/adapter-cli/README.md' => maa_adapter_read( $root . '/packages/adapter-cli/README.md' ),
	'docs/openclaw-ai-image-ratio-crop-media-adoption-recipe.md' => maa_adapter_read( $root . '/docs/openclaw-ai-image-ratio-crop-media-adoption-recipe.md' ),
	'docs/openclaw-media-derivative-cloud-recipe.md' => maa_adapter_read( $root . '/docs/openclaw-media-derivative-cloud-recipe.md' ),
	'docs/ai-media-derivative-calling-guide.md' => maa_adapter_read( $root . '/docs/ai-media-derivative-calling-guide.md' ),
	'docs/openclaw-consumer-acceptance.md' => maa_adapter_read( $root . '/docs/openclaw-consumer-acceptance.md' ),
	'docs/openclaw-adapter-contract.md' => maa_adapter_read( $root . '/docs/openclaw-adapter-contract.md' ),
);
foreach ( $current_boundary_docs as $doc_name => $doc_body ) {
	foreach (
		array(
			'POST /wp-json/npcink-openclaw-adapter/v1/media-derivative-runs',
			'GET /wp-json/npcink-openclaw-adapter/v1/media-derivative-runs',
			'GET /wp-json/npcink-openclaw-adapter/v1/media-derivative-artifacts',
			'POST /wp-json/npcink-openclaw-adapter/v1/media-derivative-proposal-payload',
		) as $forbidden
	) {
			maa_adapter_assert( false === strpos( $doc_body, $forbidden ), 'Current docs must not document Adapter media derivative facade routes in ' . $doc_name . ': ' . $forbidden );
		}
	}

	$adapter_cli_package = maa_adapter_read( $root . '/packages/adapter-cli/package.json' );
foreach (
	array(
		'"name": "@npcink/openclaw-adapter-cli"',
		'"version": "0.2.0"',
		'"bin"',
		'"npcink-openclaw-adapter": "bin/npcink-openclaw-adapter.mjs"',
		'"node": ">=20"',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $adapter_cli_package, $required ), 'Adapter CLI package contains required text: ' . $required );
}

maa_adapter_assert( ! file_exists( $root . '/tools/npcink-openclaw-adapter.mjs' ), 'Root unified CLI wrapper is absent; use the npm CLI package.' );
maa_adapter_assert( ! file_exists( $root . '/tools/keypair-device-pairing.mjs' ), 'Root device-pairing wrapper is absent; use the npm CLI package.' );
maa_adapter_assert( ! file_exists( $root . '/tools/keypair-adapter-request.mjs' ), 'Root request wrapper is absent; use the npm CLI package.' );

$keypair_tool = maa_adapter_read( $root . '/packages/adapter-cli/bin/keypair-device-pairing.mjs' );
foreach (
	array(
		'generateKeyPairSync',
		'ed25519',
		'connect/device/start',
		'connect/device/poll',
		'X-Npcink-Key-Id',
		'X-Npcink-Signature',
		'Authorization',
		'Npcink-Signature',
		'NPCINK-AI-CLIENT-ADAPTER-V1',
		'insecure-local-tls',
		'no-open',
		'openApprovalUrl',
		'isRetryableNetworkError',
		'Transient polling error',
		'Health check failed after pairing',
		'Object.keys(value).length === 0',
		'private_key_jwk',
		'keypair-profiles',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $keypair_tool, $required ), 'Packaged keypair pairing tool contains expected behavior: ' . $required );
}
maa_adapter_assert( false === strpos( $keypair_tool, 'console.log(private' ), 'Keypair tool does not print private key material.' );

$keypair_request_tool = maa_adapter_read( $root . '/packages/adapter-cli/bin/keypair-adapter-request.mjs' );
foreach (
	array(
		'createPrivateKey',
		'private_key_jwk',
		'keypair-profiles',
		'isSafeAdapterRoute',
		'--body-file',
		'body-stdin',
		'--intent=preview|preflight|commit',
		'--query',
		'--query-string',
		'isFinalWriteRoute',
		'containsPreviewOnlyMarker',
		'enforceExecutionIntent',
		'Refusing final Adapter execute route without --intent=commit',
		'dry-run, commit=false, or commit_execution=false preview markers',
		'Npcink-Signature',
		'Authorization',
		'X-Npcink-Signature',
		'NPCINK-AI-CLIENT-ADAPTER-V1',
		'Object.keys(value).length === 0',
		'wrapper_failed',
		'redactOutput',
		'isSensitiveOutputKey',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $keypair_request_tool, $required ), 'Packaged keypair request wrapper contains expected behavior: ' . $required );
}
maa_adapter_assert( false === strpos( $keypair_request_tool, 'console.log(profile' ), 'Keypair request wrapper does not print profile data.' );
maa_adapter_assert( false === strpos( $keypair_request_tool, 'console.log(headers' ), 'Keypair request wrapper does not print signature headers.' );

$magick_adapter_tool = maa_adapter_read( $root . '/packages/adapter-cli/bin/npcink-openclaw-adapter.mjs' );
foreach (
	array(
		'connect',
		'status',
		'request',
		'read-request',
		'read-ability',
		'recipe',
			'ai-image-ratio-crop-media-adoption',
			'AI_IMAGE_RATIO_CROP_RECIPE_ID',
			'loadAiImageRatioCropRecipe',
			'recipeAiImageAdoptionPlan',
			'requestJsonViaWrapper',
			'openclaw_recipes.${AI_IMAGE_RATIO_CROP_RECIPE_ID}',
		'target_aspect_ratio_required',
		'ai_generation_dimensions_are_advisory',
		'cloud_crop_required_for_generated_images',
		'adapter_artifact_registry',
		'--submit-proposal',
			'--source-type=ai_generated',
			"copyParsedValue(parsed, input, 'source-type', 'source_type')",
		"copyParsedValue(parsed, input, 'source-page-url', 'source_page_url')",
		"copyParsedValue(parsed, input, 'attribution-text', 'attribution_text')",
		"copyParsedInt(parsed, input, 'attach-to-post-id', 'attach_to_post_id')",
			'Cloud crop and result transport belongs to Cloud Addon or Cloud tooling',
			'reviewed Cloud Addon or Cloud media derivative result',
			'keypair-device-pairing.mjs',
		'keypair-adapter-request.mjs',
		'missing_profile',
		'health_failed',
		'profile_configured',
		'core_capabilities',
		'abilities_catalog',
		'commit_execution',
		'proposal_execution',
		'available_via_adapter_routes',
		'readRequestCreate',
		'readRequestStatus',
		'inputPayloadFromArgs',
		'redactOutput',
		'isSensitiveOutputKey',
		'read-request create requires --ability-id, --purpose, and --data-classes.',
		'false values indicate Core keeps final execution authority separate from Adapter diagnostics.',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $magick_adapter_tool, $required ), 'Packaged unified local CLI contains expected behavior: ' . $required );
}
maa_adapter_assert( false === strpos( $magick_adapter_tool, 'private_key_jwk:' ), 'Unified local CLI does not read private key material by property access.' );
maa_adapter_assert( false === strpos( $magick_adapter_tool, 'connection_id: String' ), 'Unified local CLI does not print connection id from status metadata.' );
maa_adapter_assert( false === strpos( $magick_adapter_tool, 'key_id: String' ), 'Unified local CLI does not print key id from status metadata.' );
maa_adapter_assert( false === strpos( $magick_adapter_tool, '/media-derivative-runs' ), 'Unified local CLI does not call Adapter media derivative run routes.' );
maa_adapter_assert( false === strpos( $magick_adapter_tool, '/media-derivative-artifacts' ), 'Unified local CLI does not call Adapter media derivative artifact routes.' );
maa_adapter_assert( false === strpos( $magick_adapter_tool, '/media-derivative-proposal-payload' ), 'Unified local CLI does not call Adapter media derivative proposal payload routes.' );
maa_adapter_assert( false === strpos( $magick_adapter_tool, 'recipeAiImageCrop(' ), 'Unified local CLI no longer owns Cloud crop dispatch.' );
maa_adapter_assert( false === strpos( $magick_adapter_tool, 'recipeAiImageCropResult(' ), 'Unified local CLI no longer owns Cloud result reads.' );
maa_adapter_assert( false !== strpos( $keypair_tool, "'npcink.execute'" ), 'Keypair pairing CLI requests explicit execute scope for approved execution routes.' );
maa_adapter_assert( false !== strpos( $connection_page, "'npcink.execute'" ), 'Connection approval page describes explicit execute scope.' );
maa_adapter_assert( false === strpos( $magick_adapter_tool, "copyParsedValue(parsed, input, 'source', 'source')" ), 'AI image adoption CLI does not pass unsupported source input.' );
maa_adapter_assert( false === strpos( $magick_adapter_tool, "copyParsedValue(parsed, input, 'attribution', 'attribution')" ), 'AI image adoption CLI does not pass unsupported attribution input.' );
maa_adapter_assert( false === strpos( $magick_adapter_tool, "copyParsedValue(parsed, input, 'external-thread-id', 'external_thread_id')" ), 'AI image adoption CLI keeps external thread id in caller metadata only.' );

$wporg_readme = maa_adapter_read( $root . '/readme.txt' );
foreach (
	array(
		'=== Npcink AI Client Adapter ===',
		'Requires at least: 7.0',
		'Tested up to: 7.0',
		'Requires PHP: 8.0',
		'Requires Plugins: npcink-abilities-toolkit, npcink-governance-core',
		'Stable tag: 0.3.2',
		'License: GPL-2.0-or-later',
		'structured missing dependency error',
		'machine-readable `client_policy`',
		'local CLI also redacts profile paths',
		'Npcink Governance Core remains the governance backend',
		'npcink-abilities-toolkit/trash-post',
		'= 0.3.2 =',
		'= 0.3.1 =',
		'= 0.3.0 =',
		'Add Adapter-declared Core and Abilities Toolkit compatibility floors to the machine-readable contract metadata',
		'Record a signed local AI client create-draft proposal, approve-and-execute, readback, and cleanup acceptance pass',
		'= 0.2.1 =',
		'Reject batch write actions that request `core_proxy_execute=true` before Adapter execution',
		'= 0.2.0 =',
		'Add machine-readable Adapter contract metadata and stable contract hashes',
		'Add the local AI client acceptance command for non-destructive Adapter/Core boundary checks',
		'Add local AI client CLI helper commands, output redaction, and machine-readable client policy',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $wporg_readme, $required ), 'readme.txt contains required text: ' . $required );
}

$local_ai_client_policy = maa_adapter_read( $root . '/docs/local-ai-client-policy.md' );
foreach (
	array(
		'Local AI Client Policy',
		'does not control which AI client a customer chooses',
		'Adapter-Owned Controls',
		'Customer-Selected Client Boundary',
		'npcink_openclaw_adapter_client_policy.v1',
		'policy_version',
		'adapter_contract_version',
		'core_contract_min_version',
		'core_plugin_min_version',
		'toolkit_contract_min_version',
		'toolkit_plugin_min_version',
		'execution_profile_registry_hash',
		'forbidden_outputs',
		'forbidden_local_access',
		'adapter_relative_routes_only=true',
		'read-request create',
		'read-ability',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $local_ai_client_policy, $required ), 'Local AI client policy doc contains required text: ' . $required );
}

$composer = maa_adapter_read( $root . '/composer.json' );
foreach (
	array(
		'"license": "GPL-2.0-or-later"',
		'"package:release"',
		'"package:suite"',
		'"plugin-check:release"',
		'"smoke:package-install": "bash tests/package-install-smoke.sh"',
		'"accept:local-ai-client": "bash tests/local-ai-client-acceptance.sh"',
		'"accept:local-ai-client-fixture": "bash tests/local-ai-client-fixture-acceptance.sh"',
		'"accept:openclaw-zhihu-atomics": "bash tests/openclaw-zhihu-atomics-acceptance.sh"',
		'"visual:wp": "bash tests/visual-acceptance.sh"',
		'"dev:article-template-visual": "bash tests/dev-article-template-visual.sh"',
		'"dev:block-theme-template-visual": "bash tests/dev-block-theme-template-visual.sh"',
		'"eval:project:quality": "sh scripts/eval-lab.sh task=project_quality_gate',
		'Local Sites/magick-ai/app/public',
		'command -v wp',
		'php-8.5.3+1',
		'--exclude-directories=tests,.git,vendor,node_modules,build,sj',
		'--exclude-files=.gitignore,.distignore,AGENTS.md,composer.json',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $composer, $required ), 'composer.json contains required text: ' . $required );
}
maa_adapter_assert( false === strpos( $composer, '@eval:lab' ) && false === strpos( $composer, '@eval:project:quality' ), 'Default Adapter test and release scripts do not require eval-lab.' );

$zhihu_atomics_acceptance = maa_adapter_read( $root . '/tests/openclaw-zhihu-atomics-acceptance.sh' );
foreach (
	array(
		'npcink-toolbox/cloud-web-search',
		'tests/fixtures/openclaw-zhihu-atomics',
		'read-ability',
		'zhihu_hot_topics',
		'zhihu_research',
		'zhihu_global_search',
		'zhida_simple',
		'zhida_deep',
		'zhida_deepsearch',
		'article_research_pack.v1',
		'expected_outputs',
		'atomic_outputs',
		'MAA_ADAPTER_ZHIHU_ATOMICS_SLEEP_SECONDS',
		'write_posture',
		'direct_wordpress_write',
		'core_proposal_required',
	) as $required_zhihu_atomics_acceptance
) {
	maa_adapter_assert( false !== strpos( $zhihu_atomics_acceptance, $required_zhihu_atomics_acceptance ), 'Zhihu atomics acceptance script contains required text: ' . $required_zhihu_atomics_acceptance );
}

foreach (
	array(
		'tests/fixtures/openclaw-zhihu-atomics/zhihu-hot-topics.input.json' => array( 'zhihu_hot_topics', 'managed_source' ),
		'tests/fixtures/openclaw-zhihu-atomics/zhihu-search.input.json' => array( 'zhihu_research', 'managed_source' ),
		'tests/fixtures/openclaw-zhihu-atomics/global-search.input.json' => array( 'zhihu_global_search', 'managed_source' ),
		'tests/fixtures/openclaw-zhihu-atomics/zhida-simple.input.json' => array( 'zhida_simple', 'managed_source' ),
		'tests/fixtures/openclaw-zhihu-atomics/zhida-deep.input.json' => array( 'zhida_deep', 'managed_source' ),
		'tests/fixtures/openclaw-zhihu-atomics/zhida-deepsearch.input.json' => array( 'zhida_deepsearch', 'managed_source' ),
		'tests/fixtures/openclaw-zhihu-atomics/article-research-pack.sequence.json' => array( 'article_research_pack.v1', 'openclaw_atoms.zhihu_hot_topics', 'openclaw_atoms.zhihu_search', 'openclaw_atoms.global_search', 'openclaw_atoms.zhida_answer' ),
	) as $fixture_path => $fixture_required_texts
) {
	$fixture = maa_adapter_read( $root . '/' . $fixture_path );
	maa_adapter_assert( '' !== $fixture, 'OpenClaw Zhihu atom fixture exists: ' . $fixture_path );
	foreach ( $fixture_required_texts as $fixture_required_text ) {
		maa_adapter_assert( false !== strpos( $fixture, $fixture_required_text ), 'OpenClaw Zhihu atom fixture contains required text in ' . $fixture_path . ': ' . $fixture_required_text );
	}
}

$local_ai_client_acceptance_sh = maa_adapter_read( $root . '/tests/local-ai-client-acceptance.sh' );
foreach (
	array(
		'MAA_ADAPTER_ACCEPTANCE_PROFILE',
		'GET /health',
		'GET /connection/manifest',
		'GET /help',
		'npcink-abilities-toolkit/site-info',
		'MAA_ADAPTER_ACCEPTANCE_SENSITIVE_READ_REQUEST_ID',
		'--intent=preflight',
		'MAA_ADAPTER_ACCEPTANCE_ALLOW_COMMIT',
		'--intent=commit',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $local_ai_client_acceptance_sh, $required ), 'Local AI client acceptance script contains required text: ' . $required );
}

$local_ai_client_acceptance_doc = maa_adapter_read( $root . '/docs/local-ai-client-acceptance.md' );
foreach (
	array(
		'Local AI Client Acceptance',
		'non-destructive',
		'composer accept:local-ai-client',
		'MAA_ADAPTER_ACCEPTANCE_SENSITIVE_READ_ABILITY',
		'MAA_ADAPTER_ACCEPTANCE_PREFLIGHT_PROPOSAL_ID',
		'MAA_ADAPTER_ACCEPTANCE_ALLOW_COMMIT=1',
		'client_policy',
		'contract',
		'unsupported abilities or unapproved proposals fail closed',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $local_ai_client_acceptance_doc, $required ), 'Local AI client acceptance doc contains required text: ' . $required );
}

$local_ai_client_e2e_doc = maa_adapter_read( $root . '/docs/local-ai-client-e2e-acceptance-2026-06-15.md' );
foreach (
	array(
		'Local AI Client E2E Acceptance - 2026-06-15',
		'packages/adapter-cli/bin/npcink-openclaw-adapter.mjs request --profile=local --insecure-local-tls',
		'POST /proposals',
		'POST /proposals/{proposal_id}/approve-and-execute --intent=commit',
		'npcink-abilities-toolkit/create-draft',
		'1e4f63ca-ac29-458e-8edd-532af30de3c4',
		'post_id": 282608',
		'cleaned_post_id=282608',
		'Success: Deleted post 282607.',
		'core_proxy_execute=false',
		'commit_execution=false',
		'core_contract_min_version',
		'not Core-emitted or Toolkit-emitted runtime proofs',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $local_ai_client_e2e_doc, $required ), 'Local AI client E2E doc contains required text: ' . $required );
}

$eval_lab_proxy = maa_adapter_read( $root . '/scripts/eval-lab.sh' );
maa_adapter_assert( false !== strpos( $eval_lab_proxy, 'NPCINK_EVAL_LAB_PATH' ) && false !== strpos( $eval_lab_proxy, 'composer eval:task -- "$@"' ), 'Eval-lab proxy supports override path and task registry dispatch.' );
maa_adapter_assert( false !== strpos( $eval_lab_proxy, 'composer "$SCRIPT" -- "$@"' ), 'Eval-lab proxy keeps legacy Composer entrypoint compatibility.' );
maa_adapter_assert( false === strpos( $composer . "\n" . $eval_lab_proxy, 'sk-' ), 'Eval-lab integration does not contain committed provider keys.' );

$distribution_contract = maa_adapter_read( $root . '/docs/distribution-contract.md' );
foreach (
	array(
		'Npcink AI Suite Distribution Contract',
		'`npcink-abilities-toolkit` and `npcink-governance-core` are the current',
		"Adapter's own slug is treated as a distribution contract value",
		'npcink_openclaw_adapter_missing_dependency',
		'Distribution unifies installation, not responsibilities',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $distribution_contract, $required ), 'Distribution contract contains required text: ' . $required );
}

$suite_packager = maa_adapter_read( $root . '/scripts/package-suite.sh' );
foreach (
	array(
		'NPCINK_GOVERNANCE_CORE_DIR',
		'NPCINK_ABILITIES_TOOLKIT_DIR',
		'npcink-ai-suite',
		'npcink-ai-client-adapter.zip',
		'npcink-governance-core.zip',
		'npcink-abilities-toolkit.zip',
		'VERSION_MATRIX.md',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $suite_packager, $required ), 'Suite packager contains required text: ' . $required );
}

$distignore = maa_adapter_read( $root . '/.distignore' );
foreach (
	array(
		'.git',
		'.distignore',
		'.gitignore',
		'AGENTS.md',
		'build',
		'composer.json',
		'tests',
		'tools',
		'packages',
		'vendor',
		'node_modules',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $distignore, $required ), '.distignore excludes development artifact: ' . $required );
}

$quickstart = maa_adapter_read( $root . '/docs/openclaw-quickstart.md' );
foreach (
	array(
		'OpenClaw Quickstart',
		'OpenClaw-compatible local AI client',
		'the local AI client connects to Adapter',
		'https://npcink.local',
		'WordPress administrator username: `1`',
		'WordPress administrator password: `1`',
		'Application Password',
		'higher-security signed key-pair',
		'GET /health',
		'GET /help',
		'GET /capabilities',
		'Public Key Device Pairing',
		'cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.2.0 -- npcink-openclaw-adapter',
		'does not keep root-level `tools/` compatibility',
		'connect/device/start',
		'POST /proposals/from-plan',
		'/proposals?limit=10',
		'/proposals/PROPOSAL_ID',
		'proposals:read',
			'audit_timeline',
			'NPCINK_OPENCLAW_ADAPTER_CORE_APP_TOKEN',
				'npcink_openclaw_adapter_core_app_token',
				'approve-and-execute',
				'npcink_openclaw_adapter_execute_profile_unsupported',
			'approval_surface=npcink_governance_core_admin',
		'log_context',
		'wpai_request_log_context',
		'proposal_id',
		'correlation_id',
		'adapter_request_id',
		'adapter_route',
		'ai_provider',
		'ai_model',
		'downstream AI client, Cloud',
		'Adapter does not expose a provider/model smoke endpoint',
		'Core Governance Audit is the governance log',
		'AI Request Logs are the provider request log',
			'/site-info',
			'/active-plugins-detail',
			'/plugin-conflict-diagnostics',
			'/recent-error-log',
			'/recent-error-log-tail',
			'/current-user-permissions',
			'/database-info',
			'/posts?author_id=1',
			'/terms?taxonomy=category&include_sample_posts=1',
			'/menu?location=primary',
		'/media?per_page=1',
		'/content-inventory-fix-plan?per_page=1&max_actions=1',
		'npcink-abilities-toolkit/build-content-inventory-fix-plan',
		'write_actions',
		'not as completed writes',
		'/pages?per_page=1',
		'wp-diagnostics-summary` to decide whether plugin details',
		'include_inactive_plugins=true',
		'Default inactive plugin rows are not missing',
		'error_log.summary.fatal_count',
		'not explicitly requested',
		'contents_included=true',
		'error_log.summary.by_severity',
			'Proposal-Required Write Flow',
			'core_proxy_execute=false',
			'commit_execution=false',
			'Dry-run-only verification stops at Adapter commit-preflight',
			'normalizes ability input to `dry_run=false` and `commit=true`',
			'execution_mode=batch_write_actions',
			'outside that execution supported profiles',
		) as $required
) {
	maa_adapter_assert( false !== strpos( $quickstart, $required ), 'Quickstart contains required text: ' . $required );
}

$connection_model_notes = maa_adapter_read( $root . '/docs/openclaw-connection-model-notes.md' );
foreach (
	array(
		'OpenClaw Connection Model Notes',
		'Default: simple Application Password connection',
		'Higher security: local signed key-pair',
		'@npcink/openclaw-adapter-cli@0.2.0',
		'sh: npcink-openclaw-adapter: command not found',
		'Secret Handling Rules',
		'Plugin-generated private keys',
		'Adapter-owned MCP broker',
		'Core remains the proposal, approval, preflight, execution-outcome, and audit',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $connection_model_notes, $required ), 'Connection model notes contain required text: ' . $required );
}

$contract = maa_adapter_read( $root . '/docs/openclaw-adapter-contract.md' );
foreach (
	array(
		'initial productization contract',
		'npcink-abilities-toolkit',
		'npcink-governance-core',
		'Machine-Readable Contract Metadata',
		'Adapter contract version `3`',
		'core_contract_min_version',
		'core_plugin_min_version',
		'toolkit_contract_min_version',
		'toolkit_plugin_min_version',
		'not Core-emitted or Toolkit-emitted runtime proofs',
		'dependency_contracts',
		'npcink_governance_core_contract.v1',
		'npcink_abilities_toolkit_contract.v1',
		'provider_secret_storage',
		'host_governed_writes',
		'ability_catalog_source=wordpress_abilities_api',
		'input_schema_source=wordpress_abilities_api',
		'output_schema_source=wordpress_abilities_api',
		'callback_free_hashes=true',
		'stable_contract_hashes=true',
		'read_execution_surface=wordpress_abilities_api',
		'write_execution_surface=host_runtime_after_governance',
		'It must not copy raw ability definitions',
		'core_proxy_execute',
		'commit_execution',
		'execution_handoff_posture',
		'implementation_posture',
		'implementation_posture_evidence',
		'npcink_openclaw_adapter_execution_handoff_posture.v1',
		'generic_write_executor=false',
		'workflow_runtime=false',
		'queue_or_scheduler=false',
		'Npcink -> Adapter',
		'Application Password Handoff',
		'Proposal Status Read Proxy',
			'Approval Disabled Stub Contract',
			'OpenClaw only connects to Adapter',
			'unified_action_route',
			'approve-and-execute',
			'show the raw password',
		'It must not store',
		'raw secrets in adapter options, manifest JSON, handoff text',
		'username `1` and password `1`',
		'Proposal-Required Write Flow',
		'Dry-run-only proposal verification stops at Adapter commit-preflight',
		'Adapter `execute`, `execute-approved-proposal`, and `approve-and-execute` routes are',
		'normalizes the ability input to `dry_run=false` and `commit=true`',
		'GET /wp-json/npcink-openclaw-adapter/v1/help',
		'GET /wp-json/npcink-openclaw-adapter/v1/proposals',
		'GET /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}',
		'POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan',
		'POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/execute',
		'POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/approve-and-execute',
		'POST /wp-json/npcink-openclaw-adapter/v1/run-read-ability',
		'Adapter does not expose direct-read shortcut routes',
			'approval_surface=npcink_governance_core_admin',
		'proposals:read',
		'audit_timeline',
		'wp-diagnostics-summary` is only a quick overview',
		'Adapter does not expose a provider smoke route',
		'Core Governance Audit is the governance log',
		'adapter_request_id',
		'adapter_route',
		'governance_source=npcink-governance-core',
		'npcink_governance_core.proposal_id',
		'npcink_governance_core.correlation_id',
		'include_log_contents',
		'include_inactive_plugins',
		'max_plugins_per_group',
		'tail_lines',
		'since_minutes',
		'not explicitly requested',
		'Inactive plugin rows are not requested by default',
		'fatal_count',
		'warning_count',
		'deprecated_count',
		'notice_count',
		'summary_source',
		'error_log.tail_entries',
		'error_log.summary.by_severity',
		'include_log_tail',
		'Npcink runtime, MCP, or cloud status',
		'plugin_file',
		'network_active',
		'dependency_count',
		'npcink_permissions',
		'severity_filter',
		'since_minutes',
		'author_id',
		'include_sample_posts',
		'author_profile',
		'attached_to',
		'npcink-abilities-toolkit/get-menu',
		'wpai_request_log_context',
		'log_context',
		'proposal_id',
		'correlation_id',
		'NPCINK_OPENCLAW_ADAPTER_CORE_APP_TOKEN',
		'npcink_openclaw_adapter_core_app_token',
		'Read-Only Planning Contract',
		'npcink-abilities-toolkit/build-content-inventory-fix-plan',
		'npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan',
		'npcink-abilities-toolkit/build-media-inventory-fix-plan',
		'npcink-abilities-toolkit/build-media-reference-repair-plan',
		'npcink-abilities-toolkit/build-media-settings-reference-repair-plan',
		'npcink-abilities-toolkit/build-article-optimization-apply-plan',
		'npcink-abilities-toolkit/build-pattern-page-plan',
		'npcink-toolbox/build-article-write-plan',
		'npcink-toolbox/build-article-batch-write-plan',
		'npcink-toolbox/build-article-media-batch-write-plan',
		'openclaw_recipes.article_batch_draft_plan',
			'openclaw_recipes.article_media_batch_plan',
			'openclaw_recipes.site_edit_router',
			'untrusted_user_prompt_to_allowed_recipe',
			'prompt_is_authorization=false',
			'fail_closed',
			'Toolbox may expose click-driven buttons for the same fixed flows',
		'same ability ids, artifact',
		'Core proposal handoff routes',
		'OpenClaw recipe owner',
		'proposal truth, approval surface, or write executor',
		'article_batch_write_plan',
		'article_media_batch_write_plan',
		'pattern_page_plan',
		'skipped_destructive_candidates',
		'npcink-abilities-toolkit/delete-media-permanently',
		'Approved Proposal Execution Contract',
		'Unified Approve And Execute Contract',
		'openclaw-batch-execution-policy.md',
		'batch containing any non-supported action fails',
		'npcink-abilities-toolkit/trash-post',
		'npcink-abilities-toolkit/create-draft',
		'npcink-abilities-toolkit/update-post',
		'npcink-abilities-toolkit/patch-post-content',
		'npcink-abilities-toolkit/update-post-blocks',
		'npcink-abilities-toolkit/patch-setting-value',
		'npcink-abilities-toolkit/set-post-seo-meta',
		'npcink-abilities-toolkit/set-post-slug',
		'npcink-abilities-toolkit/set-post-terms',
		'npcink-abilities-toolkit/delete-term',
		'npcink-abilities-toolkit/update-media-details',
		'npcink-abilities-toolkit/optimize-media-asset',
		'npcink-abilities-toolkit/replace-media-file',
		'npcink-abilities-toolkit/restore-media-backup',
		'npcink-abilities-toolkit/adopt-cloud-media-derivative',
		'npcink-abilities-toolkit/rename-media-file',
		'npcink-abilities-toolkit/delete-media-permanently',
		'npcink-abilities-toolkit/reply-comment',
		'npcink-abilities-toolkit/trash-comment',
		'npcink-abilities-toolkit/approve-comment',
		'execution profile registry',
		'capability discovery',
		'POST /proposals',
		'undeclared input fields',
		'invalid enum values',
		'`npcink-abilities-toolkit/update-post` does not accept `status`',
		'$outputs.<prior_action_id>.<field>',
		'cannot be embedded into larger strings',
		'POST /wp-json/npcink-governance-core/v1/proposals/from-plan',
		'The read path does not execute abilities marked `proposal_required`.',
		'read_authorization_required',
		'core_read_authorization_required',
		'required_flow=core_read_request',
		'custom scripts',
		'The adapter does not store proposal governance state.',
		'explicit unified approve-and-execute action',
		'All routes require `manage_options`',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $contract, $required ), 'Adapter contract contains required text: ' . $required );
}

$acceptance = maa_adapter_read( $root . '/docs/openclaw-consumer-acceptance.md' );
foreach (
	array(
		'OpenClaw Consumer Acceptance',
		'OpenClaw must not connect directly to Npcink Governance Core for productized use.',
		'GET /health',
		'GET /help',
		'GET /capabilities',
		'GET /proposals',
		'GET /proposals/{proposal_id}',
		'POST /proposals',
		'POST /proposals/from-plan',
		'POST /proposals/{proposal_id}/commit-preflight',
		'POST /proposals/{proposal_id}/execute',
		'POST /proposals/{proposal_id}/approve-and-execute',
		'execution_mode=batch_write_actions',
		'For dry-run-only validation, stop at this step and do not call',
		'normalized ability input to `dry_run=false` and',
		'status=failed',
			'failed action metadata',
			'does not store the full proposal or create a retry queue',
			'openclaw_recipes.site_edit_router',
			'prompt_is_authorization=false',
			'default_behavior=fail_closed',
			'npcink_openclaw_adapter_write_action_invalid',
			'complete image `src`/`alt` attributes',
			'non-empty heading and',
			'Gutenberg-native spacing on key sections',
			'plan -> proposal -> approve-and-execute -> get-post-blocks',
			'composer visual:wp',
			'build/visual-acceptance/report.json',
		'approved proposal execution',
		'approve-and-execute',
		'npcink-abilities-toolkit/trash-post',
		'npcink-abilities-toolkit/create-draft',
		'npcink-abilities-toolkit/update-post',
		'npcink-abilities-toolkit/patch-post-content',
		'npcink-abilities-toolkit/update-post-blocks',
		'npcink-abilities-toolkit/patch-setting-value',
		'npcink-abilities-toolkit/set-post-seo-meta',
		'npcink-abilities-toolkit/set-post-slug',
		'npcink-abilities-toolkit/set-post-terms',
		'npcink-abilities-toolkit/delete-term',
		'npcink-abilities-toolkit/update-media-details',
		'npcink-abilities-toolkit/delete-media-permanently',
		'npcink-abilities-toolkit/reply-comment',
		'npcink-abilities-toolkit/trash-comment',
		'npcink-abilities-toolkit/approve-comment',
		'active plugin detail input',
		'plugin conflict diagnostic input',
		'current user permission input',
		'database info input',
		'include_log_contents=true',
		'wp-ops-diagnostics-detail',
		'include_log_contents=false',
		'include_inactive_plugins=false',
		'include_inactive_plugins=true',
		'not explicitly',
		'error_log.summary.fatal_count',
		'error_log.tail_entries',
		'error_log.summary.by_severity',
		'Core Governance Audit',
		'AI Request Logs',
		'Do not call an Adapter provider/model smoke route',
		'downstream AI client, Cloud runtime, or provider integration',
		'explicit `ai_provider`',
		'explicit `ai_model`',
			'adapter_request_id',
			'governance_source=npcink-governance-core',
			'provider request log',
			'npcink-abilities-toolkit/build-content-inventory-fix-plan',
		'npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan',
		'npcink-abilities-toolkit/build-media-inventory-fix-plan',
		'npcink-abilities-toolkit/build-media-reference-repair-plan',
		'npcink-abilities-toolkit/build-media-settings-reference-repair-plan',
		'npcink-toolbox/build-article-write-plan',
		'npcink-toolbox/build-article-batch-write-plan',
		'npcink-toolbox/build-article-media-batch-write-plan',
		'openclaw_recipes.article_batch_draft_plan',
		'openclaw_recipes.article_media_batch_plan',
		'openclaw_recipes.pattern_page_plan',
		'openclaw_recipes.pattern_page_research_brief',
		'openclaw_recipes.pattern_page_with_visual_asset_plan',
		'core/image.attrs.id',
		'core/media-text.attrs.mediaId',
		'wp-image-{id}',
		'temporary Cloud derivative preview URLs',
		'npcink-toolbox/build-content-discoverability-brief',
		'primary_contract=true',
		'`seo`,',
		'`aeo`,',
		'`geo`,',
		'`exceptions`,',
		'`special_cases`,',
		'`proposal_allowed_fields`',
		'primary SEO/GEO/AEO entrypoint',
		'plan-to-proposal forwarding',
		'skipped_destructive_candidates',
		'write_actions',
		'commit_execution=false',
		'no new runtime ownership',
		'composer plugin-check:release',
		'composer package:release',
		'Local Fixture Cleanup',
		'Local smoke and HTTP acceptance fixtures must be registered',
		'cleanup before any assertion that can fail',
		'register_shutdown_function',
		'try/finally',
		'Core proposal rows',
		'Core audit rows',
		'Adapter execution',
		'Negative Loop Reject Fixture',
		'Negative Loop Preflight Fixture',
		'OpenClaw HTTP acceptance draft',
		'Operator Loop Article Draft',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $acceptance, $required ), 'OpenClaw acceptance doc contains required text: ' . $required );
}

$agents = maa_adapter_read( $root . '/AGENTS.md' );
foreach (
	array(
		'Product name: Npcink AI Client Adapter',
		'thin OpenClaw-compatible channel layer',
		'does not own',
		'explicit post-Core execution profile policy',
		'generic final write authority',
		'npcink-workflow-toolbox',
		'must not register recipes',
			'external ability ids only',
			'does not own their callbacks',
		'Do not add provider/model/prompt execution routes',
		'metadata-only context forwarding',
		'npcink-cloud-addon',
		'Do not add Adapter-owned Cloud',
		'npcink-abilities-toolkit',
		'npcink-governance-core',
		'core_proxy_execute=false',
		'commit_execution=false',
		'composer plugin-check:release',
		'composer package:release',
		'.distignore',
) as $required
) {
	maa_adapter_assert( false !== strpos( $agents, $required ), 'AGENTS.md contains required text: ' . $required );
}
maa_adapter_assert(
	false === strpos( $agents, "- final write execution policy;" ),
	'AGENTS.md no longer forbids the explicit post-Core execution profile policy.'
);

$smoke_sh = maa_adapter_read( $root . '/tests/smoke-wp.sh' );
foreach (
	array(
		'wp-content/plugins/npcink-ai-client-adapter',
		'wp plugin activate npcink-ai-client-adapter',
		'eval-file "$ROOT_DIR/tests/smoke-wp.php"',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $smoke_sh, $required ), 'Smoke shell contains required text: ' . $required );
}

$block_theme_openclaw_acceptance_sh = maa_adapter_read( $root . '/tests/block-theme-openclaw-acceptance.sh' );
foreach (
	array(
		'MAA_ADAPTER_BLOCK_THEME_OPENCLAW_ACCEPTANCE_OUT',
		'WP_CLI_MYSQL_SOCKET',
		'mysqli.default_socket=$WP_CLI_MYSQL_SOCKET',
		'wp-content/plugins/npcink-ai-client-adapter',
		'wp plugin activate npcink-ai-client-adapter',
		'eval-file "$ROOT_DIR/tests/block-theme-openclaw-acceptance.php"',
		'build/block-theme-openclaw-acceptance/report.json',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $block_theme_openclaw_acceptance_sh, $required ), 'Block theme OpenClaw acceptance shell contains required text: ' . $required );
}

$block_theme_openclaw_acceptance = maa_adapter_read( $root . '/tests/block-theme-openclaw-acceptance.php' );
foreach (
	array(
		'block_theme_openclaw_acceptance_report',
		'MAA_ADAPTER_BLOCK_THEME_OPENCLAW_ACCEPTANCE_STRICT_FUTURE',
		'/npcink-openclaw-adapter/v1/health',
		'/npcink-openclaw-adapter/v1/help',
		'/npcink-openclaw-adapter/v1/capabilities',
		'npcink-abilities-toolkit/route-content-intent',
		'npcink-abilities-toolkit/get-block-theme-context',
		'npcink-abilities-toolkit/get-template-blocks',
		'npcink-abilities-toolkit/inspect-gutenberg-composition-contract',
		'Natural-language route input contains only prompt',
		'front-page',
		'home',
		'index',
		'no_hint_breadcrumb_check',
		'broken_breadcrumb_detection',
		'template_layout_route',
		'customize_template_layout',
		'site_template_layout',
		'template_placement_contract_failed',
		'build_block_theme_site_plan',
		'product_gaps',
		'npcink-abilities-toolkit',
		'created_proposal',
		'executed_write',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $block_theme_openclaw_acceptance, $required ), 'Block theme OpenClaw acceptance harness contains required text: ' . $required );
}

$visual_acceptance_sh = maa_adapter_read( $root . '/tests/visual-acceptance.sh' );
foreach (
	array(
		'MAA_ADAPTER_VISUAL_ACCEPTANCE_OUT',
		'MAA_ADAPTER_KEEP_VISUAL_ACCEPTANCE_FIXTURES=1',
		'MAA_ADAPTER_VISUAL_ACCEPTANCE_SKIP_SMOKE',
		'MAA_ADAPTER_VISUAL_ACCEPTANCE_KEEP_FIXTURES_AFTER_RUN',
		'MAA_ADAPTER_VISUAL_ACCEPTANCE_INSTALL_BROWSER',
		'MAA_ADAPTER_VISUAL_ACCEPTANCE_CREATE_TEMP_ADMIN',
		'TEMP_ADMIN_USER_ID',
		'cleanup_temp_admin',
		'run_wp user create',
		'run_wp user delete',
		'WP_ADMIN_USER',
		'WP_ADMIN_PASSWORD',
		'|| -n "${WP_CLI_MYSQL_SOCKET:-}"',
		'mysqli.default_socket=$WP_CLI_MYSQL_SOCKET',
		'build/visual-acceptance-node',
		'npm install --prefix "$NODE_DEPS_DIR" --no-save playwright',
		'playwright" install chromium',
		'scripts/gutenberg-visual-acceptance.mjs',
		'tests/cleanup-visual-acceptance.php',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $visual_acceptance_sh, $required ), 'Visual acceptance shell contains required text: ' . $required );
}

$dev_article_template_visual_sh = maa_adapter_read( $root . '/tests/dev-article-template-visual.sh' );
foreach (
	array(
		'MAA_ADAPTER_DEV_ARTICLE_VISUAL_REPORT_DIR',
		'MAA_ADAPTER_DEV_ARTICLE_VISUAL_BACKUP',
		'MAA_ADAPTER_DEV_ARTICLE_VISUAL_KEEP_TEMPLATE',
		'restore_template',
		'trap restore_template EXIT',
		'tests/dev-article-template-visual.php',
		'composer visual:wp',
		'MAA_ADAPTER_VISUAL_ACCEPTANCE_SKIP_SMOKE=1',
		'WP_CLI_MYSQL_SOCKET',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $dev_article_template_visual_sh, $required ), 'Dev article template visual shell contains required text: ' . $required );
}

$dev_article_template_visual_php = maa_adapter_read( $root . '/tests/dev-article-template-visual.php' );
foreach (
	array(
		'local_dev_only',
		'/npcink-openclaw-adapter/v1/run-read-ability',
		'npcink-abilities-toolkit/build-block-theme-site-plan',
		'customize_template_layout',
		'article_standard',
		'serialize_blocks',
		'wp_update_post',
		'MAA_ADAPTER_DEV_ARTICLE_VISUAL_MODE',
		'MAA_ADAPTER_DEV_ARTICLE_VISUAL_POST_ID',
		'minimum_padded_sections',
		'block_theme_template',
		'block_editor_url',
		'site-editor.php?postType=wp_template',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $dev_article_template_visual_php, $required ), 'Dev article template visual PHP contains required text: ' . $required );
}

$dev_block_theme_template_visual_sh = maa_adapter_read( $root . '/tests/dev-block-theme-template-visual.sh' );
foreach (
	array(
		'MAA_ADAPTER_BLOCK_THEME_VISUAL_REPORT_DIR',
		'MAA_ADAPTER_BLOCK_THEME_VISUAL_PROFILE',
		'MAA_ADAPTER_BLOCK_THEME_VISUAL_BACKUP',
		'MAA_ADAPTER_BLOCK_THEME_VISUAL_KEEP_TEMPLATE',
		'restore_template',
		'trap restore_template EXIT',
		'tests/dev-block-theme-template-visual.php',
		'composer visual:wp',
		'MAA_ADAPTER_VISUAL_ACCEPTANCE_SKIP_SMOKE=1',
		'WP_CLI_MYSQL_SOCKET',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $dev_block_theme_template_visual_sh, $required ), 'Dev block theme template visual shell contains required text: ' . $required );
}

$dev_block_theme_template_visual_php = maa_adapter_read( $root . '/tests/dev-block-theme-template-visual.php' );
foreach (
	array(
		'local_dev_only',
		'/npcink-openclaw-adapter/v1/run-read-ability',
		'npcink-abilities-toolkit/build-block-theme-site-plan',
		'customize_template_layout',
		'article_standard',
		'page_standard',
		'homepage_landing',
		'serialize_blocks',
		'wp_update_post',
		'MAA_ADAPTER_BLOCK_THEME_VISUAL_MODE',
		'MAA_ADAPTER_BLOCK_THEME_VISUAL_POST_ID',
		'MAA_ADAPTER_BLOCK_THEME_VISUAL_PAGE_ID',
		'minimum_padded_sections',
		'block_theme_template',
		'block_theme_homepage',
		'block_editor_url',
		'site-editor.php?postType=wp_template',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $dev_block_theme_template_visual_php, $required ), 'Dev block theme template visual PHP contains required text: ' . $required );
}

$package_install_smoke_sh = maa_adapter_read( $root . '/tests/package-install-smoke.sh' );
foreach (
	array(
		'MAA_ADAPTER_PACKAGE_SMOKE_ZIP',
		'build/npcink-ai-client-adapter.zip',
		'restore_original_plugin',
		'trap restore_original_plugin EXIT',
		'wp-content/plugins/npcink-ai-client-adapter',
		'wp plugin install',
		'Npcink AI Client Adapter',
		'Legacy bootstrap file should not be packaged.',
		'/npcink-openclaw-adapter/v1/health',
		'npcink_openclaw_adapter_contract.v1',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $package_install_smoke_sh, $required ), 'Package install smoke shell contains required text: ' . $required );
}

$local_ai_client_fixture_acceptance_sh = maa_adapter_read( $root . '/tests/local-ai-client-fixture-acceptance.sh' );
foreach (
	array(
		'MAA_ADAPTER_FIXTURE_ALLOW_COMMIT',
		'MAA_ADAPTER_FIXTURE_CLEANUP_POST',
		'Adapter CLI fixture draft proposal',
		'npcink-abilities-toolkit/create-draft',
		'POST /proposals --body-file',
		'GET "/proposals/$proposal_id"',
		'approve-and-execute',
		'--intent=commit',
		'Final route succeeded without --intent=commit',
		'core_preflight_evidence.authorized',
		'core_preflight_evidence.approved_input_hash',
		'execution_record.core_execution_record.recorded',
		'execution_record.core_execution_record.status',
		'wp post get "$post_id" --field=post_status',
		'wp post get "$post_id" --field=post_title',
		'GET "/proposals/$proposal_id"',
		'Executed proposal status did not preserve original adapter_request_id',
		'npcink_openclaw_adapter_execution_already_completed',
		'wp post delete',
		'local AI client fixture acceptance: ok',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $local_ai_client_fixture_acceptance_sh, $required ), 'Local AI client fixture acceptance shell contains required text: ' . $required );
}

$visual_acceptance_cleanup = maa_adapter_read( $root . '/tests/cleanup-visual-acceptance.php' );
foreach (
	array(
		'Cleaned visual acceptance fixtures',
		'wp_delete_attachment',
		'wp_delete_post',
		'MAA_ADAPTER_VISUAL_ACCEPTANCE_OUT',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $visual_acceptance_cleanup, $required ), 'Visual acceptance cleanup contains required text: ' . $required );
}

$visual_acceptance_runner = maa_adapter_read( $root . '/scripts/gutenberg-visual-acceptance.mjs' );
foreach (
	array(
		'createRequire',
		"require('playwright')",
		'MAA_ADAPTER_VISUAL_ACCEPTANCE_BROWSER_CHANNEL',
		"browserLaunchOptions.channel = 'chrome'",
		'horizontalOverflow',
		'block_theme_template',
		'required_blocks',
		'require_images',
		'validate_images',
		'block theme template renders a visible main area',
		'block theme template renders a main H1',
		'block theme template renders latest posts',
		'block theme template renders category links',
		'visible images loaded',
		'visible images have alt text',
		'visible controls stay within viewport',
		'key sections have visible spacing',
		'front end opened the fixture page',
		'low_background_variety',
		'WP_ADMIN_USER',
		'WP_ADMIN_PASSWORD',
		'block editor showed invalid block recovery prompt',
		'report.json',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $visual_acceptance_runner, $required ), 'Visual acceptance runner contains required text: ' . $required );
}

$smoke_wp_visual_contract = maa_adapter_read( $root . '/tests/smoke-wp.php' );
foreach (
	array(
		'visual_acceptance_post_status',
		'adapter visual acceptance fixture is temporarily published for anonymous browser rendering',
		'MAA_ADAPTER_KEEP_VISUAL_ACCEPTANCE_FIXTURES',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $smoke_wp_visual_contract, $required ), 'Smoke visual acceptance contract contains required text: ' . $required );
}

$smoke_wp = maa_adapter_read( $root . '/tests/smoke-wp.php' );
foreach (
	array(
		'/npcink-openclaw-adapter/v1/health',
		'/npcink-openclaw-adapter/v1/help',
		'/npcink-openclaw-adapter/v1/capabilities',
		'/npcink-openclaw-adapter/v1/run-read-ability',
		'adapter help does not expose OpenClaw recipe catalog',
		'adapter forwards Toolbox article plan to Core',
		'adapter article plan creates one Core proposal',
		'adapter article plan creates only a WordPress draft',
		'/npcink-openclaw-adapter/v1/proposals',
		'/npcink-openclaw-adapter/v1/proposals/from-plan',
		'adapter plan-to-proposal rejects invalid profiled action input before Core forwarding',
		'invalid-update-post-status',
		'adapter plan action input rejection carries field',
		'adapter from-plan output references create one batch proposal',
		'adapter from-plan output-reference batch approve-and-execute succeeds',
		'adapter content metadata apply plan creates one Core batch proposal',
		'adapter content metadata apply batch approve-and-execute succeeds',
		'adapter content metadata apply batch writes reviewed excerpt',
		'adapter content metadata apply batch assigns reviewed category',
		'adapter content metadata apply batch assigns reviewed tag',
		'adapter plan-to-proposal rejects duplicate action ids before Core forwarding',
		'adapter plan-to-proposal rejects embedded output reference tokens before Core forwarding',
		'adapter batch approve-and-execute rejects embedded output reference tokens before execution',
		'maa_adapter_smoke_assert_gutenberg_images_are_complete',
		'maa_adapter_smoke_assert_gutenberg_content_quality',
		'adapter pattern page execution verifies post-block readback',
		'adapter pattern page draft preserves reviewed media URL',
		'adapter pattern page draft preserves reviewed media alt text',
		'keeps Gutenberg-native spacing on key sections',
		'adapter article block execution verifies post-block readback',
		'adapter article block draft preserves reviewed media URL',
		'adapter article block draft preserves reviewed media alt text',
		'maa_adapter_smoke_count_padded_blocks',
		'/execute',
		'/npcink-openclaw-adapter/v1/execute-approved-proposal',
		'/approve-and-execute',
		'/npcink-governance-core/v1/audit',
			'npcink-abilities-toolkit/site-info',
			'npcink-abilities-toolkit/wp-diagnostics-summary',
			'npcink-abilities-toolkit/wp-ops-diagnostics-detail',
			'npcink-abilities-toolkit/list-workflow-recipes',
		'npcink-abilities-toolkit/get-workflow-recipe',
		'npcink-abilities-toolkit/build-content-inventory-fix-plan',
		'npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan',
		'npcink-abilities-toolkit/build-media-inventory-fix-plan',
		'npcink-abilities-toolkit/build-media-reference-repair-plan',
		'npcink-abilities-toolkit/build-media-settings-reference-repair-plan',
		'npcink-toolbox/build-article-write-plan',
			'adapter health does not expose diagnostic shortcut defaults',
			'adapter diagnostic shortcut route is removed',
			'npcink_governance_core',
			'adapter_request_id',
			'adapter_route',
			'does not expose provider routing context field',
		'does not expose model routing context field',
		'governance_source',
		'npcink_governance_core',
		'provider smoke route is removed',
		'adapter refuses direct execution for proposal-required ability',
		'adapter refuses direct execution for destructive ability',
		'adapter health exposes approved proposal execution route',
		'adapter health exposes approve-and-execute route',
		'adapter health exposes trash-post execute supported profiles',
		'adapter health exposes rename-media-file execute supported profiles',
		'adapter health exposes delete-media-permanently execute supported profiles',
		'adapter health reports dependency contracts ready',
		'adapter health detects Core provider secret storage disabled',
		'maa_adapter_smoke_assert_contract_snapshot',
		'contract snapshot exposes only expected keys',
		'adapter connection manifest contract snapshot matches health',
		'adapter connection manifest includes ready dependency contracts',
		'adapter help contract snapshot matches health',
		'adapter help includes ready dependency contracts',
		'adapter help exposes execute-approved-proposal route',
		'adapter help exposes proposal execute route',
		'adapter help exposes proposal approve-and-execute route',
		'adapter smoke created trash-post fixture',
		'adapter creates trash-post proposal for approved execution smoke',
		'adapter trash-post proposal starts pending',
		'adapter execute refuses pending proposal',
		'adapter pending execute leaves post published',
		'Core admin REST approval succeeds for adapter trash execution smoke',
		'adapter executes approved trash-post proposal',
		'adapter execute response carries proposal id',
		'adapter execute response carries ability id',
		'adapter execute response carries correlation id',
		'adapter execute response carries adapter request id',
		'adapter execute response carries approval context',
		'adapter execute response preserves commit_execution=false',
		'adapter execute trashes post',
		'adapter execute returns non-dry-run ability result',
		'adapter approved execution moves post to trash',
		'adapter rejects duplicate approved proposal execution',
		'adapter duplicate execute uses completed execution error code',
		'adapter duplicate execute returns stored execution record',
		'adapter duplicate execute preserves original adapter request id',
		'adapter creates trash-post proposal for approve-and-execute smoke',
		'adapter approve-and-execute succeeds for pending trash-post proposal',
		'adapter approve-and-execute response carries proposal id',
		'adapter approve-and-execute response carries ability id',
		'adapter approve-and-execute response carries post id',
		'adapter approve-and-execute records pending status before approval',
		'adapter approve-and-execute auto approves pending proposal through Core',
		'adapter approve-and-execute response carries correlation id',
		'adapter approve-and-execute preserves Core commit_execution=false',
		'adapter approve-and-execute records post status before execution',
		'adapter approve-and-execute records post status after execution',
		'adapter approve-and-execute execution result succeeds',
		'adapter approve-and-execute moves pending proposal post to trash',
		'adapter rejects duplicate approve-and-execute request',
		'adapter duplicate approve-and-execute uses completed execution error code',
		'adapter duplicate approve-and-execute returns stored execution record',
		'adapter duplicate approve-and-execute preserves original adapter request id',
		'adapter rejects duplicate create-draft approve-and-execute request',
		'adapter creates write_actions batch proposal for approve-and-execute smoke',
		'adapter batch approve-and-execute succeeds for supported write_actions',
		'adapter batch approve-and-execute reports batch execution mode',
		'adapter batch approve-and-execute returns per-action results',
		'adapter creates output-reference write_actions batch proposal',
		'adapter batch approve-and-execute succeeds with output references',
		'adapter output-reference batch updates the created draft',
		'adapter output-reference batch leaves created draft trashed',
		'adapter batch approve-and-execute rejects non-supported write_action',
		'adapter bad batch does not execute allowed action before failing closed',
		'adapter batch approve-and-execute rejects core_proxy_execute write_action',
		'npcink_openclaw_adapter_write_action_core_proxy_execute_unsupported',
		'adapter core_proxy_execute batch does not execute allowed action',
		'adapter batch approve-and-execute rejects commit_execution write_action',
		'npcink_openclaw_adapter_write_action_commit_execution_unsupported',
		'adapter commit_execution batch does not execute allowed action',
		'adapter approve-and-execute succeeds for already approved proposal',
		'adapter approve-and-execute records approved status before execution',
		'adapter approve-and-execute skips approve for already approved proposal',
		'adapter approve-and-execute moves already approved post to trash',
		'adapter approve-and-execute rejects rejected proposal',
		'adapter rejected proposal response returns operator feedback',
		'adapter rejected proposal feedback preserves Core rejection note',
		'adapter approve-and-execute does not execute rejected proposal',
		'adapter approve-and-execute returns preflight failure',
		'adapter preflight-blocked response returns operator feedback',
		'adapter preflight-blocked feedback preserves no Core execution',
		'adapter approve-and-execute does not execute preflight-blocked proposal',
		'adapter article plan handoff returns operator feedback',
		'adapter article plan handoff feedback preserves Core error code',
		'adapter article plan handoff feedback marks revision retryable',
		'adapter proposal create rejects update-post without update fields',
		'adapter proposal create rejects update-post status input',
		'adapter update-post status rejection uses schema field error code',
		'adapter creates set-post-seo-meta proposal for approve-and-execute smoke',
		'adapter proposal create rejects set-post-seo-meta without SEO fields',
		'adapter creates set-post-slug proposal for approve-and-execute smoke',
		'adapter proposal create rejects set-post-slug without valid slug',
		'adapter rejects duplicate set-post-terms approve-and-execute request',
		'adapter proposal create rejects set-post-terms without terms',
		'adapter proposal create rejects set-post-terms create_missing',
		'adapter creates delete-term proposal for approve-and-execute smoke',
		'adapter approve-and-execute succeeds for pending delete-term proposal',
		'adapter proposal create rejects delete-term without term_id',
		'adapter proposal create rejects delete-term invalid taxonomy',
		'adapter creates update-media-details proposal for approve-and-execute smoke',
		'adapter proposal create rejects update-media-details without detail fields',
		'adapter creates rename-media-file proposal for dry-run preflight smoke',
		'Core admin REST approval succeeds for rename-media-file dry-run preflight smoke',
		'adapter rename-media-file dry-run preflight preserves Core commit_execution=false',
		'adapter rename-media-file dry-run preflight caches handoff without executing',
		'adapter rename-media-file dry-run preflight leaves attached file pointer unchanged',
		'adapter rename-media-file dry-run preflight leaves media URL unchanged',
		'adapter rename-media-file dry-run preflight leaves original media file in place',
		'adapter creates rename-media-file proposal for approve-and-execute smoke',
		'adapter approve-and-execute succeeds for pending rename-media-file proposal',
		'adapter approve-and-execute response carries rename-media-file ability id',
		'adapter rename-media-file execution returns non-dry-run ability result',
		'adapter rename-media-file execution reports rename',
		'adapter rename-media-file commit updates attached file pointer',
		'adapter rename-media-file commit moves old media file',
		'adapter rename-media-file commit writes target media file',
		'adapter rename-media-file commit changes media URL',
		'adapter rename-media-file commit URL contains target file name',
		'adapter creates delete-media-permanently proposal for approve-and-execute smoke',
		'adapter approve-and-execute succeeds for pending delete-media-permanently proposal',
		'adapter approve-and-execute deletes media attachment permanently',
		'adapter proposal create rejects delete-media-permanently without attachment_id',
		'adapter proposal create rejects delete-media-permanently for non-attachment post',
		'adapter proposal create rejects reply-comment without content',
		'adapter creates trash-comment proposal for approve-and-execute smoke',
		'adapter proposal create rejects trash-comment without comment_id',
		'adapter proposal create rejects approve-comment without comment_id',
		'adapter approve-and-execute rejects non-supported ability',
		'adapter capabilities expose content inventory fix plan through Core',
		'adapter capabilities expose nonproduction content cleanup plan through Core',
		'adapter capabilities expose media inventory fix plan through Core',
		'adapter plan read preserves write_actions',
		'adapter plan read preserves preview',
		'adapter plan read preserves commit_execution=false',
		'adapter plan read carries internal read policy',
		'adapter diagnostic shortcut route is removed',
		'adapter read response carries generated correlation id',
		'adapter media plan preserves skipped destructive candidates field',
		'adapter media plan does not promote skipped deletes into write actions by default',
		'adapter media plan shortcut treats include_delete_candidates=false as false',
		'adapter media plan shortcut treats include_trash_parent_media=false as false',
		'adapter media plan shortcut treats include_unattached_nonproduction_media=false as false',
		'adapter forwards plan-to-proposal route to Core',
		'adapter rejects unallowed plan-to-proposal ability before Core forwarding',
		'adapter plan proposal caller carries adapter request id',
		'adapter e2e media plan creates Core proposal',
		'adapter e2e plan proposal detail preserves source plan preview',
		'adapter e2e plan proposal detail preserves manual review rows',
		'adapter e2e plan proposal detail preserves skipped destructive candidates',
		'adapter e2e media plan keeps delete-media-permanently out of created proposals by default',
		'POST /npcink-openclaw-adapter/v1/proposals/from-plan accepts multi media optimization plan',
		'adapter multi media optimization creates one Core batch proposal',
		'adapter multi media optimization Core proposal stores four actions',
		'adapter multi media optimization execute succeeds after one Core batch approval',
		'adapter multi media optimization executes four actions together',
		'adapter multi media optimization verification records two derivative adoption items',
		'adapter multi media optimization writes expected artifact bytes for each attachment',
		'adapter media optimization checksum mismatch returns stable ability error code',
		'adapter media optimization checksum mismatch identifies failed action id',
		'adapter media optimization checksum mismatch reports already executed metadata action',
		'adapter media optimization checksum mismatch execution record counts partial success',
		'adapter media optimization checksum mismatch leaves attachment file pointer unchanged',
		'adapter expected current file mismatch returns stable ability error code',
		'adapter expected current file mismatch stores failed execution record',
		'adapter expected current file mismatch leaves attachment file pointer unchanged',
		'adapter plan-to-proposal response preserves proposal list state',
		'adapter help exposes proposal list route',
		'adapter help exposes proposal detail route',
		'adapter help does not expose standalone approval route',
		'adapter help does not expose standalone rejection route',
		'adapter health exposes Core app token configured state without token value',
		'adapter help exposes Core app token configured state without token value',
		'adapter smoke created scoped Core app token',
		'adapter Core app token does not include approval scope',
		'adapter Core app token does not include audit read scope',
		'adapter creates proposal through Core app token',
		'adapter app-token proposal stores app attribution',
		'adapter app-token commit preflight returns policy version',
		'adapter app-token commit preflight hash matches proposal input',
		'adapter app-token error response',
		'Core audit stores Adapter app attribution for proposal creation',
		'Core audit stores Adapter app attribution for commit preflight',
		'adapter returns created proposal in Core proposal list',
		'adapter returns proposal detail through Core',
		'adapter proposal detail preserves audit timeline',
		'adapter read response exposes AI request log context',
		'adapter read log context carries proposal id',
		'adapter read log context carries correlation id',
		'adapter health does not expose expanded read shortcut routes',
		'adapter diagnostic shortcut route is removed',
		'removed diagnostic shortcut returns rest_no_route',
		'adapter standalone approval route is absent',
			'adapter standalone rejection route is absent',
			'absent standalone approval route does not change Core proposal status',
			'absent standalone rejection route does not change Core proposal status',
			'adapter provider smoke route is removed from the thin channel surface',
		'maa_adapter_smoke_capture_observability_event',
		'adapter emits OpenClaw dispatch completed event for health',
		'adapter emits OpenClaw dispatch failed event for plan handoff failure',
		'adapter emits Core relay success event for capabilities',
		'adapter emits Core relay failure event for missing proposal',
		'adapter emits proposal create success observability event',
		'adapter emits proposal create failure observability event',
		'adapter emits plan handoff success observability event',
		'adapter emits plan handoff failure observability event',
		'adapter emits commit preflight success observability event',
		'adapter emits commit preflight failure observability event',
			'adapter help does not expose AI article writing pack shortcut route',
			'adapter runs content discoverability brief primary ability',
			'content discoverability brief returns the expected artifact type',
			'content discoverability brief is marked as the primary SEO/GEO/AEO contract',
			'content discoverability brief is suggestion-only',
			'content discoverability brief points final writes to Core proposals',
			'content discoverability brief disables direct WordPress writes',
			'content discoverability brief exposes SEO section',
			'content discoverability brief exposes AEO section',
			'content discoverability brief exposes GEO section',
			'content discoverability brief exposes exceptions',
			'content discoverability brief exposes special cases',
			'content discoverability brief exposes proposal allowed fields',
			'excludes raw key ',
		'maa_adapter_smoke_fixture_registry',
		'maa_adapter_smoke_register_attachment_fixture',
		'_npcink_openclaw_adapter_smoke_fixture_run_id',
		'maa_adapter_smoke_known_media_fixture_leak_ids',
		'maa_adapter_smoke_known_media_fixture_file_paths',
		'codex-commit-',
		'maa_adapter_smoke_assert_no_media_fixture_leaks',
		'maa_adapter_smoke_cleanup_registered_fixtures',
		'register_shutdown_function',
		'npcink_openclaw_adapter_execution_records',
		'npcink_openclaw_adapter_preflight_handoffs',
		'md5( $cleanup_proposal_id )',
		'adapter cached preflight handoff execute succeeds',
		'adapter failed execution returns failed execution record',
		'adapter failed execution record carries ability error code',
		'adapter failed execution record keeps commit_execution=false',
		'adapter status smoke cleaned created proposal records',
		'adapter smoke leaves no registered or reserved-prefix media fixtures behind',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $smoke_wp, $required ), 'WordPress smoke contains required text: ' . $required );
}

$article_recipe = maa_adapter_read( $root . '/docs/openclaw-article-draft-plan-recipe.md' );
foreach (
	array(
		'OpenClaw Article Draft Plan Recipe',
		'npcink-toolbox/build-article-write-plan',
		'npcink-abilities-toolkit/create-draft',
		'POST /wp-json/npcink-openclaw-adapter/v1/run-read-ability',
		'POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan',
		'POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/approve-and-execute',
		'artifact_type=article_write_plan',
		'risk_level=high',
		'blocked_claims',
		'Cloud Addon is not part of this local control loop',
		'Adapter must not publish, schedule, approve standalone, or execute arbitrary writes',
		'local article assistant handoff',
		'not a bulk writing flow',
		'hosted drafting path',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $article_recipe, $required ), 'Article draft recipe contains required text: ' . $required );
}

$article_batch_recipe = maa_adapter_read( $root . '/docs/openclaw-article-batch-draft-plan-recipe.md' );
foreach (
	array(
		'OpenClaw Article Batch Draft Plan Recipe',
		'npcink-toolbox/build-article-batch-write-plan',
		'article_batch_write_plan',
		'proposal_mode=batch',
		'batch_approval=true',
		'npcink-abilities-toolkit/create-draft',
		'POST /wp-json/npcink-openclaw-adapter/v1/run-read-ability',
		'POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan',
		'POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/approve-and-execute',
		'status=draft',
		'core_proxy_execute=false',
		'commit_execution=false',
		'publish_allowed=false',
		'partial_success=false',
		'fail closed',
		'not make Adapter the article generator',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $article_batch_recipe, $required ), 'Article batch draft recipe contains required text: ' . $required );
}

$content_discoverability_recipe = maa_adapter_read( $root . '/docs/openclaw-content-discoverability-recipe.md' );
foreach (
	array(
		'OpenClaw Content Discoverability Recipe',
		'The primary SEO/GEO/AEO entrypoint is `content-discoverability-brief`',
		'Use `article-writing-pack` only for broad natural-language requests',
		'openclaw_recipes.ai_article_draft_with_discoverability',
		'POST /wp-json/npcink-openclaw-adapter/v1/run-read-ability',
		'npcink-toolbox/build-ai-article-writing-pack',
		'npcink-toolbox/validate-content-discoverability-context',
		'npcink-toolbox/get-content-discoverability-context',
		'npcink-toolbox/build-content-discoverability-brief',
			'externally registered WordPress ability ids',
			'discovered through Adapter capabilities',
		'POST /wp-json/npcink-openclaw-adapter/v1/run-read-ability',
		'"ability_id": "npcink-toolbox/validate-content-discoverability-context"',
		'"ability_id": "npcink-toolbox/get-content-discoverability-context"',
		'"ability_id": "npcink-toolbox/build-content-discoverability-brief"',
		'"search_policy"',
		'"requires_external_evidence": true',
		'"max_results": 3',
		'"recency_days": 30',
		'"enhance_with_reader": false',
		'Cloud owns the search providers',
		'cloud_evidence.web_search',
		'governance_mode=direct_read',
		'execution_surface=wp_abilities_rest',
		'write_posture',
		'suggestion_only',
		'direct_wordpress_write',
		'final_write_path',
		'core_proposal_required',
		'Do not add these Toolbox abilities to Adapter',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $content_discoverability_recipe, $required ), 'Content discoverability recipe contains required text: ' . $required );
}

$article_writing_pack_recipe = maa_adapter_read( $root . '/docs/openclaw-ai-article-writing-pack-recipe.md' );
foreach (
	array(
		'OpenClaw AI Article Writing Pack Recipe',
		'For SEO/GEO/AEO suggestions on a known post',
		'Use `article-writing-pack` only for broad natural-language requests',
		'npcink-toolbox/build-ai-article-writing-pack',
			'externally registered WordPress ability ids',
			'discovered through Adapter capabilities',
		'POST /wp-json/npcink-openclaw-adapter/v1/run-read-ability',
		'"ability_id": "npcink-toolbox/build-ai-article-writing-pack"',
		'"search_policy"',
		'"requires_external_evidence": true',
		'"max_results": 3',
		'"recency_days": 30',
		'"enhance_with_reader": false',
		'Cloud owns the search providers',
		'cloud_evidence.web_search when external evidence is available',
		'governance_mode=direct_read',
		'execution_surface=wp_abilities_rest',
		'artifact_type=ai_article_writing_pack',
		'write_posture=suggestion_only',
		'direct_wordpress_write=false',
		'provider_execution=none',
		'final_write_path=core_proposal_required',
		'npcink-toolbox/build-article-write-plan',
		'npcink-toolbox/build-article-batch-write-plan',
		'npcink-toolbox/build-article-media-batch-write-plan',
		'POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan',
		'Do not publish, schedule, upload media, set featured images, write SEO meta',
		'not an article generator product',
		'local review candidate',
		'batch article writing',
		'article drafts that also include selected image-source',
		'Do not present OpenClaw as the article generator',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $article_writing_pack_recipe, $required ), 'AI article writing pack recipe contains required text: ' . $required );
}

$article_media_batch_recipe = maa_adapter_read( $root . '/docs/openclaw-article-media-batch-plan-recipe.md' );
foreach (
	array(
		'OpenClaw Article Media Batch Plan Recipe',
		'npcink-toolbox/build-article-media-batch-write-plan',
		'article_media_batch_write_plan',
		'npcink-toolbox/search-image-source',
		'npcink-abilities-toolkit/create-draft',
		'npcink-abilities-toolkit/upload-media-from-url',
		'npcink-abilities-toolkit/update-media-details',
		'npcink-abilities-toolkit/set-post-featured-image',
		'POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan',
		'POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/approve-and-execute',
		'image source attribution is preserved',
		'core_proxy_execute=false',
		'commit_execution=false',
		'publish_allowed=false',
		'partial_success=false',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $article_media_batch_recipe, $required ), 'Article media batch recipe contains required text: ' . $required );
}

$pattern_page_recipe = maa_adapter_read( $root . '/docs/openclaw-pattern-page-plan-recipe.md' );
foreach (
	array(
		'OpenClaw Pattern Page Plan Recipe',
		'npcink-abilities-toolkit/build-pattern-page-plan',
		'pattern_page_plan',
		'npcink-abilities-toolkit/create-draft',
		'npcink-abilities-toolkit/update-post-blocks',
		'POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan',
		'POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/approve-and-execute',
		'responsive_profile',
		'landing_standard',
		'media_strategy',
		'existing_media_url',
		'design_quality.pattern_version',
		'design_quality.design_system=gutenberg_native_v1',
		'design_quality.section_shape_variety >= 4',
		'design_quality.template_similarity_score <= 0.75',
		'design_quality.variant_reason',
		'responsive_quality.uses_mobile_stack=true',
		'GET /wp-json/npcink-openclaw-adapter/v1/post-blocks?post_id={post_id}',
		'isStackedOnMobile=true',
		'core/media-text',
		'core/details',
		'1440px, 768px, and 390px',
		'openclaw_recipes.pattern_page_plan.visual_acceptance',
		'MAA_ADAPTER_VISUAL_ACCEPTANCE_OUT',
		'MAA_ADAPTER_KEEP_VISUAL_ACCEPTANCE_FIXTURES',
		'openclaw-gutenberg-visual-acceptance.md',
		'openclaw-gutenberg-design-system.md',
		'Adapter does not fetch, upload, generate, or select media',
		'pattern_renderer_owner=npcink-abilities-toolkit',
		'allowed_responsive_profiles=["landing_standard"]',
		'allowed_media_strategies=["mock_or_existing_media","existing_media_url"]',
		'design_system_contract=docs/openclaw-gutenberg-design-system.md',
		'core_proxy_execute=false',
		'commit_execution=false',
		'direct_wordpress_write=false',
		'Adapter must not accept arbitrary CSS',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $pattern_page_recipe, $required ), 'Pattern page plan recipe contains required text: ' . $required );
}

$article_block_recipe = maa_adapter_read( $root . '/docs/openclaw-article-block-plan-recipe.md' );
foreach (
	array(
		'OpenClaw Article Block Plan Recipe',
		'npcink-abilities-toolkit/build-article-block-plan',
		'article_block_plan',
		'npcink-abilities-toolkit/create-draft',
		'npcink-abilities-toolkit/update-post-blocks',
		'POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan',
		'POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/approve-and-execute',
		'article_template',
		'comparison-review',
		'responsive_profile',
		'article_standard',
		'media_strategy',
		'existing_media_url',
		'hero_media_attachment_id',
		'core/image.attrs.id',
		'wp-image-{id}',
		'not a temporary Cloud',
		'editorial_quality.pattern_version',
		'editorial_quality.uses_native_blocks=true',
		'responsive_quality.uses_mobile_stack=true',
		'GET /wp-json/npcink-openclaw-adapter/v1/post-blocks?post_id={post_id}',
		'isStackedOnMobile=true',
		'core/image',
		'core/details',
		'1440px, 768px, and 390px',
		'openclaw_recipes.article_block_plan.visual_acceptance',
		'MAA_ADAPTER_VISUAL_ACCEPTANCE_OUT',
		'MAA_ADAPTER_KEEP_VISUAL_ACCEPTANCE_FIXTURES',
		'openclaw-gutenberg-visual-acceptance.md',
		'temporary Cloud',
		'Adapter does not fetch, upload, generate, or select media',
		'article_renderer_owner=npcink-abilities-toolkit',
		'allowed_article_templates=["editorial-longform","how-to-guide","comparison-review"]',
		'allowed_responsive_profiles=["article_standard"]',
		'allowed_media_strategies=["none","existing_media_url"]',
		'custom_css_allowed=false',
		'core_proxy_execute=false',
		'commit_execution=false',
		'direct_wordpress_write=false',
		'Adapter must not accept arbitrary CSS',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $article_block_recipe, $required ), 'Article block plan recipe contains required text: ' . $required );
}

$gutenberg_visual_acceptance = maa_adapter_read( $root . '/docs/openclaw-gutenberg-visual-acceptance.md' );
foreach (
	array(
		'OpenClaw Gutenberg Visual Acceptance',
		'openclaw_recipes.pattern_page_plan',
		'openclaw_recipes.article_block_plan',
		'openclaw_recipes.block_theme_site_plan',
		'MAA_ADAPTER_VISUAL_ACCEPTANCE_OUT',
		'MAA_ADAPTER_KEEP_VISUAL_ACCEPTANCE_FIXTURES',
		'composer visual:wp',
		'build/visual-acceptance/report.json',
		'MAA_ADAPTER_VISUAL_ACCEPTANCE_SKIP_SMOKE',
		'MAA_ADAPTER_VISUAL_ACCEPTANCE_BROWSER_CHANNEL',
		'MAA_ADAPTER_VISUAL_ACCEPTANCE_INSTALL_BROWSER',
		'core/media-text.attrs.mediaId',
		'wp-image-{id}',
		'temporary Cloud',
		'MAA_ADAPTER_VISUAL_ACCEPTANCE_CREATE_TEMP_ADMIN',
		'WP_ADMIN_USER',
		'WP_ADMIN_PASSWORD',
		'front_end_url',
		'block_editor_url',
		'attachment_ids',
		'desktop: `1440x1000`',
		'tablet: `768x1024`',
		'mobile: `390x844`',
		'front-end page has no horizontal overflow',
		'block editor opens without invalid block recovery prompts',
		'core blocks remain individually editable',
		'Design Quality Checks',
		'at least four distinct section shapes',
		'not only black and white',
		'do not share the exact same section order',
		'openclaw-gutenberg-design-system.md',
		'Do not fix visual issues by adding arbitrary CSS',
		'fixture_type=block_theme_template',
		'block theme template layout acceptance',
		'require_images=false',
		'validate_images=false',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $gutenberg_visual_acceptance, $required ), 'Gutenberg visual acceptance doc contains required text: ' . $required );
}

$gutenberg_design_system = maa_adapter_read( $root . '/docs/openclaw-gutenberg-design-system.md' );
foreach (
	array(
		'OpenClaw Gutenberg Design System',
		'natural language -> intent route -> plan -> Core proposal -> approved execute -> readback',
		'WordPress Twenty Twenty-Five patterns',
		'Frost block theme patterns',
		'Ollie block theme patterns',
		'10up Gutenberg engineering practices',
		'Toolkit owns:',
		'OpenClaw owns:',
		'Core owns:',
		'split_hero',
		'product_media_panel',
		'bento_feature_grid',
		'comparison_cards',
		'workflow_timeline',
		'editorial_lede',
		'featured_image',
		'breadcrumbs',
		'Anti-Template Rules',
		'variant_reason',
		'template_similarity_score',
		'design_system',
		'gutenberg_native_v1',
		'section_shape_variety >= 4',
		'media_coverage_score >= 0.6',
		'template_similarity_score <= 0.75',
		'Temporary Cloud preview URLs must never be referenced',
		'no horizontal overflow at 1440px, 768px, and 390px',
		'Adapter must not:',
		'store a pattern registry',
		'execute writes without Core approval and commit-preflight',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $gutenberg_design_system, $required ), 'Gutenberg design system doc contains required text: ' . $required );
}

$block_theme_template_milestone = maa_adapter_read( $root . '/docs/archive/2026-06-18-block-theme-template-milestone.md' );
foreach (
	array(
		'Block Theme Template Customization Milestone',
		'Status: accepted',
		'front-page',
		'single',
		'page',
		'article_standard',
		'page_standard',
		'homepage_landing',
		'MAA_ADAPTER_VISUAL_ACCEPTANCE_CREATE_TEMP_ADMIN=1',
		'composer dev:block-theme-template-visual',
		'MAA_ADAPTER_BLOCK_THEME_OPENCLAW_ACCEPTANCE_COMMIT=1',
		'composer accept:block-theme-openclaw',
		'editor checks are not skipped',
		'invalid block recovery prompts',
		'This is not a generic AI site builder',
		'npcink-abilities-toolkit',
		'npcink-governance-core',
		'Deferred Work',
		'arbitrary CSS',
		'theme.json',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $block_theme_template_milestone, $required ), 'Block theme template milestone doc contains required text: ' . $required );
}

$image_candidate_adoption_recipe = maa_adapter_read( $root . '/docs/openclaw-image-candidate-adoption-plan-recipe.md' );
foreach (
	array(
		'OpenClaw Image Candidate Adoption Plan Recipe',
		'npcink-toolbox/build-image-candidate-adoption-plan',
		'image_candidate_adoption_plan',
		'image_candidate.v1',
		'npcink-toolbox/search-image-source',
		'npcink-abilities-toolkit/upload-media-from-url',
		'npcink-abilities-toolkit/update-media-details',
		'npcink-abilities-toolkit/set-post-featured-image',
		'POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan',
		'POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/approve-and-execute',
		'core_proxy_execute=false',
		'commit_execution=false',
		'cloud_control_plane=false',
		'Adapter does not search providers, generate images, import media',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $image_candidate_adoption_recipe, $required ), 'Image candidate adoption recipe contains required text: ' . $required );
}

$media_adoption_enhancement_recipe = maa_adapter_read( $root . '/docs/openclaw-media-adoption-enhancement-plan-recipe.md' );
foreach (
	array(
		'OpenClaw Media Adoption Enhancement Plan Recipe',
		'npcink-abilities-toolkit/build-media-adoption-enhancement-plan',
		'media_adoption_enhancement_plan',
		'npcink-abilities-toolkit/upload-media-from-url',
		'npcink-abilities-toolkit/optimize-media-asset',
		'npcink-abilities-toolkit/patch-post-content',
		'POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan',
		'POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/approve-and-execute',
		'core_proxy_execute=false',
		'commit_execution=false',
		'cloud_control_plane=false',
		'generic_write_executor=false',
		'Adapter does not search image providers, generate images, import media',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $media_adoption_enhancement_recipe, $required ), 'Media adoption enhancement recipe contains required text: ' . $required );
}

$pattern_page_visual_asset_recipe = maa_adapter_read( $root . '/docs/openclaw-pattern-page-with-visual-asset-recipe.md' );
foreach (
	array(
		'OpenClaw Pattern Page With Visual Asset Recipe',
		'openclaw-pattern-page-research-brief-recipe.md',
		'pattern_page_with_visual_asset_plan',
		'image_candidate.v1',
		'npcink-toolbox/search-image-source',
		'npcink-toolbox/generate-image',
		'npcink-toolbox/build-image-candidate-adoption-plan',
		'npcink-abilities-toolkit/build-media-adoption-enhancement-plan',
		'npcink-abilities-toolkit/build-pattern-page-plan',
		'cloud_recommended_existing_candidate',
		'cloud_hosted_ai_generated_candidate',
		'cloud_derivative_required=true',
		'cloud_derivative_recipe_id=ai_image_ratio_crop_media_adoption',
		'media_strategy=existing_media_url',
		'variables.hero_media_url',
		'page_plan_must_reference_final_local_wordpress_media_url=true',
		'cloud_candidate_selection_allowed=true',
		'hosted_ai_generation_allowed_as_fallback=true',
		'cloud_crop_required_before_page_plan=true',
		'hosted_generation_candidate_only=true',
		'candidate_review_required=true',
		'page_references_final_local_media_url=true',
		'cloud_control_plane=false',
		'generic_write_executor=false',
		'Do not collapse this into a single direct write',
	) as $required
	) {
		maa_adapter_assert( false !== strpos( $pattern_page_visual_asset_recipe, $required ), 'Pattern page visual asset recipe contains required text: ' . $required );
	}

	$block_theme_site_builder_recipe = maa_adapter_read( $root . '/docs/openclaw-block-theme-site-builder-recipe.md' );
	foreach (
		array(
			'OpenClaw Block Theme Site Builder Recipe',
			'npcink-abilities-toolkit/get-block-theme-context',
			'npcink-abilities-toolkit/get-template-blocks',
			'npcink-abilities-toolkit/get-template-part-blocks',
			'npcink-abilities-toolkit/inspect-gutenberg-composition-contract',
			'npcink-abilities-toolkit/build-block-theme-site-plan',
			'block_theme_site_plan',
			'npcink-abilities-toolkit/update-template-blocks',
			'npcink-abilities-toolkit/upsert-template-blocks',
			'npcink-abilities-toolkit/update-template-part-blocks',
			'intent=add_breadcrumbs',
			'intent=customize_template_layout',
			'homepage_landing',
			'allowed_layout_profiles=["article_standard","page_standard","homepage_landing"]',
			'allowed_template_targets=["single","page","front-page","home","archive","index"]',
			'MAA_ADAPTER_VISUAL_ACCEPTANCE_SKIP_SMOKE=1',
			'composer dev:article-template-visual',
			'composer dev:block-theme-template-visual',
			'local-only article template visual harness',
			'local-only block theme template visual harness',
			'restores the original template content on exit',
			'block_theme_template',
			'contract_status=pass',
			'contract_inspection_required_after_execution=true',
			'global_styles_write_allowed=false',
			'navigation_write_allowed=false',
			'generic_write_executor=false',
			'cloud_control_plane=false',
			'Adapter must not accept arbitrary Site Editor writes',
		) as $required
		) {
			maa_adapter_assert( false !== strpos( $block_theme_site_builder_recipe, $required ), 'Block theme site builder recipe contains required text: ' . $required );
		}

			$site_edit_router_contract = maa_adapter_read( $root . '/docs/openclaw-site-edit-router-contract.md' );
	foreach (
		array(
			'OpenClaw Site Edit Router Contract',
			'Customer natural language is untrusted input',
			'prompt_is_authorization=false',
			'article_block_plan',
			'pattern_page_plan',
			'block_theme_site_plan',
			'route=unsupported',
			'npcink-abilities-toolkit/get-post-blocks',
			'npcink-abilities-toolkit/update-post-blocks',
			'npcink-abilities-toolkit/get-block-theme-context',
			'npcink-abilities-toolkit/inspect-gutenberg-composition-contract',
			'npcink-abilities-toolkit/update-template-blocks',
			'navigation mutations',
			'global styles mutations',
			'raw template HTML',
			'Core proposal `status=executed`',
		) as $required
		) {
			maa_adapter_assert( false !== strpos( $site_edit_router_contract, $required ), 'Site edit router contract contains required text: ' . $required );
		}

			$content_intent_router_contract = maa_adapter_read( $root . '/docs/openclaw-content-intent-router-contract.md' );
		foreach (
			array(
				'OpenClaw Content Intent Router Contract',
				'npcink-abilities-toolkit/route-content-intent',
				'content_intent_route',
				'page_landing',
				'post_article',
				'site_template_breadcrumbs',
				'openclaw_recipes.pattern_page_plan',
				'openclaw_recipes.article_block_plan',
				'openclaw_recipes.block_theme_site_plan',
				'route=unsupported',
				'needs_clarification=true',
				'Change the navigation menu and add a Products link.',
				'Change global styles and write a theme.json color patch.',
				'Directly execute a custom HTML template change.',
				'must not emit',
				'must not be submitted to `POST /proposals/from-plan`',
				'prompt_is_authorization=false',
				'direct_wordpress_write=false',
				'custom_css_allowed=false',
				'core_html_allowed=false',
			) as $required
		) {
			maa_adapter_assert( false !== strpos( $content_intent_router_contract, $required ), 'Content intent router contract contains required text: ' . $required );
		}

			$gutenberg_intent_routing_baseline = maa_adapter_read( $root . '/docs/openclaw-gutenberg-content-intent-routing-baseline.md' );
		foreach (
			array(
				'OpenClaw Gutenberg Content Intent Routing Baseline',
				'npcink-abilities-toolkit/route-content-intent',
				'pattern_page_plan',
				'article_block_plan',
				'block_theme_site_plan',
				'Negative prompts for navigation edits',
				'keep `plan_ability_id` empty',
				'emit no',
				'stop before `POST /proposals/from-plan`',
				'7874c491-89a1-4700-a07d-526937ff466c',
				'9fd06242-a936-4d9d-a693-621284e5eb6b',
				'ae94822a-0c33-4a5f-b189-b8a1578ab02a',
				'281748',
				'281750',
				'core/image.attrs.id=8053',
				'core/media-text.attrs.mediaId=8053',
				'wp-image-8053',
				'successful no-op',
				'get-post-blocks',
				'get-template-blocks',
				'get-template-part-blocks',
			) as $required
		) {
			maa_adapter_assert( false !== strpos( $gutenberg_intent_routing_baseline, $required ), 'Gutenberg intent routing baseline contains required text: ' . $required );
		}

		$ai_image_ratio_crop_media_adoption_recipe = maa_adapter_read( $root . '/docs/openclaw-ai-image-ratio-crop-media-adoption-recipe.md' );
	foreach (
	array(
		'OpenClaw AI Image Ratio Crop Media Adoption Recipe',
		'openclaw_recipes.ai_image_ratio_crop_media_adoption',
			'image_candidate.v1',
			'npcink-toolbox/generate-image',
			'Cloud image recommendation should run before generation',
			'Crop/runtime owner: `npcink-cloud-addon` or Cloud tooling, not Adapter routes',
			'Required handoff input: reviewed cropped preview URL with artifact provenance',
			'npcink-abilities-toolkit/build-media-adoption-enhancement-plan',
			'npcink-abilities-toolkit/upload-media-from-url',
		'npcink-abilities-toolkit/optimize-media-asset',
		'npcink-abilities-toolkit/patch-post-content',
		'target_aspect_ratio_required=true',
		'ai_generation_dimensions_are_advisory=true',
		'cloud_recommendation_precedes_generation=true',
		'generated_artifact_must_be_cropped_before_adoption=true',
		'final_page_reference_must_be_local_wordpress_media_url=true',
		'cloud_crop_required_for_generated_images=true',
		'candidate_review_required=true',
		'signed_preview_is_temporary=true',
			'preview_url_must_be_adopted_before_expiry=true',
			'cloud_runtime_owner=npcink-cloud-addon',
			'adapter_media_derivative_routes=false',
			'core_proxy_execute=false',
		'commit_execution=false',
		'cloud_control_plane=false',
		'adapter_artifact_registry=false',
		'generic_write_executor=false',
		'direct_wordpress_write=false',
		'Do not ask Adapter to crop arbitrary remote URLs',
	) as $required
	) {
		maa_adapter_assert( false !== strpos( $ai_image_ratio_crop_media_adoption_recipe, $required ), 'AI image ratio crop media adoption recipe contains required text: ' . $required );
	}
	foreach (
		array(
			'POST /wp-json/npcink-openclaw-adapter/v1/media-derivative-runs',
			'GET /wp-json/npcink-openclaw-adapter/v1/media-derivative-runs',
			'GET /wp-json/npcink-openclaw-adapter/v1/media-derivative-artifacts',
			'POST /media-derivative-runs',
		) as $forbidden
	) {
		maa_adapter_assert( false === strpos( $ai_image_ratio_crop_media_adoption_recipe, $forbidden ), 'AI image ratio crop recipe does not point media derivative transport at Adapter: ' . $forbidden );
	}

$pattern_page_research_brief_recipe = maa_adapter_read( $root . '/docs/openclaw-pattern-page-research-brief-recipe.md' );
foreach (
	array(
		'OpenClaw Pattern Page Research Brief Recipe',
		'openclaw_recipes.pattern_page_research_brief',
		'landing_page_research_brief',
		'npcink-toolbox/build-content-discoverability-brief',
		'competitor_research',
		'"max_results": 5',
		'"recency_days": 365',
		'"enhance_with_reader": false',
		'Cloud owns provider selection',
		'write_posture=suggestion_only',
		'direct_wordpress_write=false',
		'provider_keys_exposed=false',
		'source_attribution_required=true',
		'reference_copying_allowed=false',
		'Do not copy reference-site text, images, CSS',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $pattern_page_research_brief_recipe, $required ), 'Pattern page research brief recipe contains required text: ' . $required );
}

$batch_policy = maa_adapter_read( $root . '/docs/openclaw-batch-execution-policy.md' );
foreach (
	array(
		'OpenClaw Batch Execution Policy',
		'Status: accepted',
		'target_ability_id=npcink-abilities-toolkit/trash-post',
		'target_ability_id=npcink-abilities-toolkit/set-post-seo-meta',
			'target_ability_id=npcink-abilities-toolkit/update-post',
			'target_ability_id=npcink-abilities-toolkit/patch-post-content',
			'target_ability_id=npcink-abilities-toolkit/update-template-blocks',
			'target_ability_id=npcink-abilities-toolkit/upsert-template-blocks',
			'target_ability_id=npcink-abilities-toolkit/update-template-part-blocks',
			'target_ability_id=npcink-abilities-toolkit/patch-setting-value',
		'target_ability_id=npcink-abilities-toolkit/set-post-slug',
		'target_ability_id=npcink-abilities-toolkit/set-post-terms',
		'target_ability_id=npcink-abilities-toolkit/delete-term',
		'target_ability_id=npcink-abilities-toolkit/update-media-details',
		'target_ability_id=npcink-abilities-toolkit/upload-media-from-url',
		'target_ability_id=npcink-abilities-toolkit/set-post-featured-image',
		'target_ability_id=npcink-abilities-toolkit/optimize-media-asset',
		'target_ability_id=npcink-abilities-toolkit/replace-media-file',
		'target_ability_id=npcink-abilities-toolkit/restore-media-backup',
		'target_ability_id=npcink-abilities-toolkit/adopt-cloud-media-derivative',
		'target_ability_id=npcink-abilities-toolkit/rename-media-file',
		'target_ability_id=npcink-abilities-toolkit/delete-media-permanently',
		'target_ability_id=npcink-abilities-toolkit/reply-comment',
		'target_ability_id=npcink-abilities-toolkit/trash-comment',
		'target_ability_id=npcink-abilities-toolkit/approve-comment',
		'Execution Profile Registry',
		'Capability discovery is not enough to execute a write ability',
		'Adapter validates proposal input at `POST /proposals`',
		'POST /proposals/from-plan',
		'npcink_openclaw_adapter_plan_action_input_invalid',
		'$outputs.<prior_action_id>.<field>',
		'invalid enum values',
		'$outputs.<prior_action_id>.<field>',
		'cannot point forward',
		'cannot be embedded into larger strings',
		'fail closed',
		'Maximum batch size is 200 actions',
			'Partial success is not a normal success mode',
			'execution_mode=batch_write_actions',
			'selected_count',
			'submitted_count',
			'core_preflight_evidence',
			'per-action `execution_profile`',
			'per-action `idempotency_key`',
			'review_partial_failure_and_create_revised_proposal',
			'expand the Adapter execution supported profiles',
			'add generic proposal approval proxying',
	) as $required
) {
	maa_adapter_assert( false !== strpos( $batch_policy, $required ), 'Batch execution policy contains required text: ' . $required );
}

echo "Static contracts: ok\n";
