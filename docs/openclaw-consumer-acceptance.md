# OpenClaw Consumer Acceptance

Status: active acceptance checklist
Date: 2026-06-04

## Latest Acceptance Result

Post-governance acceptance has been refreshed after the governed media
optimization, dry-run preflight, and failed execution summary changes.

Verified commands:

```bash
composer test:all
composer smoke:wp
git diff --check
git push origin master
```

An HTTP client acceptance pass also completed successfully through a temporary
WordPress Application Password. That pass connected to Adapter for the
OpenClaw-facing flow and verified `/health`, `/help`, `/capabilities`, public,
internal, and sensitive read envelopes, diagnostics redaction,
proposal-required read refusal, proposal create/list/detail,
`approve-and-execute`, Adapter commit-preflight handoff caching, dry-run-only
preflight without WordPress mutation, duplicate execution replay protection,
bounded failed execution records, returned `proposal_id`, `correlation_id`,
`ability_id`, `execution_record`, and `core_commit_execution=false`, plus Core
Audit and AI Request Logs correlation.

The next change to OpenClaw routes, the Adapter execution supported profiles, or log
correlation fields must rerun this acceptance checklist.

## Purpose

This document defines the minimum productized OpenClaw acceptance loop for
Npcink OpenClaw Adapter.

The goal is to prove that OpenClaw can use one Adapter connection for useful
WordPress work while preserving the current project split:

- Adapter is the OpenClaw-facing WordPress REST channel.
- Core is the governance authority for proposals, approvals, preflight, and
  audit.
- Abilities are the canonical source of ability schemas, callbacks, and
  direct-read execution.
- AI Request Logs stay owned by the WordPress `ai` plugin and are correlated
  with Core audit by `proposal_id` and `correlation_id`.

## Required Runtime Shape

OpenClaw connects to:

```text
https://npcink.local/wp-json/npcink-openclaw-adapter/v1
```

OpenClaw must not connect directly to Npcink Governance Core for productized use. Core
may still be used by developers for direct governance testing, but productized
OpenClaw setup starts at Adapter.

Authentication uses a dedicated WordPress Application Password over WordPress
REST Basic Auth. Adapter must not store the raw Application Password, and
OpenClaw setup must use the non-secret connection manifest plus a dedicated
secret field or credential vault for the password.

## Local Fixture Cleanup

Local smoke and HTTP acceptance fixtures must be registered for automatic
cleanup before any assertion that can fail. Use `register_shutdown_function`,
`try/finally`, or an equivalent fixture registry so posts, attachments,
comments, terms, Core proposal rows, Core audit rows, and Adapter execution
records created by the current test are deleted on success, assertion failure,
or unexpected exit.

Negative-loop checks must register their target content separately from the
write execution path. Rejected and preflight-blocked proposals are expected not
to run `npcink-abilities-toolkit/trash-post`, so cleanup cannot depend on Adapter or Core
executing the final write. This applies to local fixtures with labels such as
`Negative Loop Reject Fixture`, `Negative Loop Preflight Fixture`,
`OpenClaw HTTP acceptance draft`, and `Operator Loop Article Draft`.

## Acceptance Flow

Run this order for a local acceptance pass:

1. Create or confirm a dedicated Application Password from
   `Npcink -> Adapter`.
2. Copy the non-secret connection manifest to OpenClaw and paste the Application
   Password only into OpenClaw's dedicated secret field. Do not paste it into
   chat, tool commands, logs, proposal payloads, files, or copied handoff text.
3. Call `GET /health`.
4. Require these health values:
   - `core_capabilities=true`
   - `abilities_catalog=true`
   -    - `approval_surface=npcink_governance_core_admin`
   - `core_proxy_execute=false`
   - `commit_execution=false`
5. Call `GET /help` and confirm route discovery includes:
   - flat `routes[]` rows with `method`, `path`, `purpose`, and `group`
   - `GET /proposals`
   - `GET /proposals/{proposal_id}`
   - `GET /connection/manifest`
   - `POST /connect/device/start`
   - `POST /connect/device/poll`
   - `GET /connection/key-pairs`
   - `POST /proposals/from-plan`
   - `POST /proposals/{proposal_id}/execute`
   - `POST /proposals/{proposal_id}/approve-and-execute`
   - no Adapter-owned `/media-derivative-runs`
   - no Adapter-owned `/media-derivative-artifacts/{artifact_id}/preview`
   - no Adapter-owned `/media-derivative-proposal-payload`
   - `route_groups` for human-readable grouped route labels
   - no `openclaw_recipes` playbook payload: recipe playbooks were removed
     from `GET /help` in the thin-channel cleanup. Each reviewed recipe is
     documented under `docs/`; confirm every recipe your flow uses has a
     current doc and that the client follows its documented steps:
     - `docs/openclaw-article-draft-plan-recipe.md`
     - `docs/openclaw-article-batch-draft-plan-recipe.md`
     - `docs/openclaw-article-media-batch-plan-recipe.md`
     - `docs/openclaw-site-edit-router-contract.md`
     - `docs/openclaw-article-block-plan-recipe.md`
     - `docs/openclaw-pattern-page-plan-recipe.md`
     - `docs/openclaw-block-theme-site-builder-recipe.md`
     - `docs/openclaw-pattern-page-research-brief-recipe.md`
     - `docs/openclaw-pattern-page-with-visual-asset-recipe.md`
     - `docs/openclaw-ai-image-ratio-crop-media-adoption-recipe.md`
     - `docs/openclaw-content-discoverability-recipe.md`
     - `docs/openclaw-ai-article-writing-pack-recipe.md`
     - `docs/openclaw-media-derivative-cloud-recipe.md`
   Confirm the pattern page and article block recipes document their
   `visual_acceptance` contract with `operator_browser_check`, front-end and
   block-editor targets, and desktop, tablet, and mobile viewport rows.
   Confirm local Gutenberg smoke verifies post-execution `get-post-blocks`
   readback, complete image `src`/`alt` attributes, non-empty heading and
   paragraph markup, and Gutenberg-native spacing on key sections before
   manual viewport review.
   When OpenClaw supplies reviewed media attachment ids, confirm article and
   page block readback preserves `core/image.attrs.id` or
   `core/media-text.attrs.mediaId`, rendered markup contains `wp-image-{id}`,
   and no generated content references temporary Cloud derivative preview URLs.
   Confirm the Site Editor router contract
   (`docs/openclaw-site-edit-router-contract.md`) documents
   `prompt_is_authorization=false`, `default_behavior=fail_closed`, and
   fail-closed surfaces for navigation and global styles before any Gutenberg
   or block-theme editing recipe is selected.
   Confirm the content intent router contract
   (`docs/openclaw-content-intent-router-contract.md`) documents
   negative acceptance examples covering navigation, global styles/theme.json,
   and custom HTML direct-execute prompts. Each example must route to
   `unsupported`, keep `plan_ability_id` empty, emit no `write_actions`, and
   stop before `POST /proposals/from-plan`.
   Confirm the pattern page with visual asset recipe documents
   `candidate_review_required=true`,
   `hosted_generation_candidate_only=true`, `cloud_control_plane=false`, and
   `generic_write_executor=false`; OpenClaw must treat hosted image generation
   as a reviewed candidate source, not a direct page write step.
   Confirm the AI image ratio crop recipe
   (`docs/openclaw-ai-image-ratio-crop-media-adoption-recipe.md`) documents
   `target_aspect_ratio_required=true`,
   `ai_generation_dimensions_are_advisory=true`,
   `cloud_crop_required_for_generated_images=true`,
   `signed_preview_is_temporary=true`, `adapter_artifact_registry=false`, and
   `direct_wordpress_write=false`; OpenClaw must adopt the cropped preview
   through a Core media adoption proposal before a page references the final
   local media URL.
   Confirm the pattern page research brief recipe documents
   `external_search_intent=competitor_research`,
   `search_policy.max_results=5`,
   `search_policy.requires_external_evidence=true`, and
   `search_policy.enhance_with_reader=false`; Adapter must not expose search
   provider keys or treat references as copyable page assets.
   Confirm the content discoverability and AI article writing recipes document a
   `default_input.search_policy` with
   `requires_external_evidence=true`, `max_results=3`, `recency_days=30`, and
   `enhance_with_reader=false`; Adapter must only pass this intent to Toolbox
   and must not expose Cloud search provider keys.
   - disabled approval and rejection stubs
   - direct-read ability execution through `/run-read-ability`
6. Call `GET /capabilities` and use only real `ability_id` values returned by
   Core guidance.
7. Run at least one direct-read ability through `POST /run-read-ability`.
8. Run at least one diagnostics read through `POST /run-read-ability` with
   `npcink-abilities-toolkit/wp-ops-diagnostics-detail`:
   - active plugin detail input
   - plugin conflict diagnostic input with `include_inactive_plugins=true`
   - current user permission input
   - database info input
   Confirm diagnostics details come from
   `npcink-abilities-toolkit/wp-ops-diagnostics-detail`, not from
   `wp-diagnostics-summary`.
   With default `include_log_contents=false`, log contents are not explicitly
   requested and must not be marked missing.
   With default `include_inactive_plugins=false`, inactive plugin rows are not
   requested and must not be marked missing. The plugin conflict diagnostic input
   must request `include_inactive_plugins=true` and return grouped plugin details for
   `plugins.active`, `plugins.inactive`, `plugins.update_available`,
   `plugins.must_use`, and `plugins.dropins`.
   Use `error_log.summary.fatal_count`, `error_log.summary.error_count`,
   `error_log.summary.warning_count`, `error_log.summary.deprecated_count`,
   `error_log.summary.notice_count`, and `error_log.summary.by_severity` for
   severity display even when log contents are not included.
   When log inspection is explicitly requested, call
   `POST /run-read-ability` with
   `npcink-abilities-toolkit/wp-ops-diagnostics-detail`,
   `include_log_contents=true`, and bounded `tail_lines`; display
   `error_log.tail_entries` plus `error_log.summary.by_severity`.
9. Create a governed write proposal with `POST /proposals`.
10. Run a planning ability such as
   `npcink-abilities-toolkit/build-content-inventory-fix-plan`,
   `npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan`,
   `npcink-abilities-toolkit/build-media-inventory-fix-plan`,
   `npcink-abilities-toolkit/build-media-reference-repair-plan`, or
   `npcink-abilities-toolkit/build-media-settings-reference-repair-plan`, or
   `npcink-toolbox/build-article-write-plan`, or
   `npcink-toolbox/build-article-batch-write-plan`, or
   `npcink-toolbox/build-article-media-batch-write-plan`. Confirm Adapter preserves
   `write_actions`, `preview`, `risk`, `requires_approval`,
   `commit_execution=false`, and `dry_run=true`, and does not treat
   `write_actions` or destructive candidates as executed work. Confirm the
   content-inventory plan read uses an approved Core read request because it
   may inspect unpublished content. Confirm its envelope carries
   `read_policy=core_read_authorization_required`, `sensitivity=sensitive`,
   `read_authorization_granted=true`, `redaction_required=true`,
   `redaction_applied=true`, a non-empty `correlation_id`, and
   `commit_execution=false`.
   For the SEO/AEO/GEO suggestion recipe, run
   `npcink-toolbox/build-content-discoverability-brief` through
   `POST /run-read-ability`; confirm the result has
   `artifact_type=content_discoverability_brief`, `primary_contract=true`,
   `write_posture=suggestion_only`, `direct_wordpress_write=false`, and
   `final_write_path=core_proposal_required`. Confirm it also exposes `seo`,
   `aeo`, `geo`, `exceptions`, `special_cases`, and
   `proposal_allowed_fields`; this is the primary SEO/GEO/AEO entrypoint.
   For broad natural-language article requests, run
   `npcink-toolbox/build-ai-article-writing-pack` through
   `POST /run-read-ability`;
   confirm the result has `artifact_type=ai_article_writing_pack`,
   `write_posture=suggestion_only`, `provider_execution=none`,
   `direct_wordpress_write=false`, and
   `final_write_path=core_proposal_required`.
   For a sensitive read such as `site-info`, confirm the adapter returns
   `npcink_openclaw_adapter_core_read_authorization_required` until Core grants
   an approved read request.
   For a diagnostics read such as `/wp-diagnostics-summary`, confirm
   `read_policy=direct_read_sensitive`, `redaction_required=true`, and
   `redaction_applied=true`.
   Attempting `POST /run-read-ability` with a proposal-required ability must
   return `npcink_openclaw_adapter_proposal_required`.
11. If a plan should become proposals, call `POST /proposals/from-plan` and
    confirm Adapter preserves Core's `proposal_count`, `proposals`,
    `blocked_items`, and `commit_execution=false` result.
    For the article draft recipe, confirm the plan has
    `artifact_type=article_write_plan`, Core creates a proposal for
    `npcink-abilities-toolkit/create-draft`, and `approve-and-execute` creates only a
    WordPress `draft`.
    For the article batch draft recipe, confirm the plan has
    `artifact_type=article_batch_write_plan`, `proposal_mode=batch`,
    `batch_approval=true`, Core creates one batch proposal, and every
    `write_actions[]` item targets `npcink-abilities-toolkit/create-draft`.
    For the article media batch recipe, confirm the plan has
    `artifact_type=article_media_batch_write_plan`, `proposal_mode=batch`,
    `batch_approval=true`, Core creates one batch proposal, image-source
    attribution is preserved, and every `write_actions[]` item targets only
    `npcink-abilities-toolkit/create-draft`, `npcink-abilities-toolkit/upload-media-from-url`,
    `npcink-abilities-toolkit/update-media-details`, or `npcink-abilities-toolkit/set-post-featured-image`.
    For the media derivative recipe, create the derivative through Cloud Addon
    or approved Cloud tooling with a test image attachment and confirm the
    result evidence includes `request_contract_version=media_derivative_cloud_request.v1`,
    `core_proposal_required=true`, and local adoption fields with
    `final_write_owner=local_wordpress_host`, `wordpress_write_included=false`,
    and `attachment_metadata_write_included=false`. Build the local plan through
    `POST /run-read-ability` with the reviewed preview URL and derivative
    evidence; confirm it returns a Core-ready plan without creating, approving,
    or executing a proposal. If the user intent is full media optimization,
    submit the returned plan to `POST /proposals/from-plan` so Core creates one
    batch proposal containing `npcink-abilities-toolkit/update-media-details` and
    `npcink-abilities-toolkit/adopt-cloud-media-derivative`. If reviewed `media_details_input`
    is missing, collect it first and rebuild the local plan; do not create a
    Core proposal yet. If Core reports the plan ability is unavailable, surface
    the capability/version guard and update the local stack. Inline media
    reference repair preview evidence stays inside derivative adoption with
    reviewed post/count expectations; do not split this same user intent into
    two proposals or a separate `patch-post-content` action. Use the
    legacy single derivative proposal only for lower-level derivative-only
    review.
12. Query status through Adapter:
   - `GET /proposals?limit=10`
   - `GET /proposals/{proposal_id}`
13. For split-path coverage, approve or reject one pending proposal in
    `Npcink -> Core`.
14. If rejected, OpenClaw stops and shows the Core status.
15. If approved and execution is intended, call
    `POST /proposals/{proposal_id}/execute`.
16. Use `POST /proposals/{proposal_id}/commit-preflight` only for advanced
    diagnostic coverage; if used, confirm Adapter reports
    `adapter_preflight_handoff_cached=true`, Core still returns
    `commit_execution=false`, and the next Adapter execute call consumes that
    handoff. For dry-run-only validation, stop at this step and do not call
    execute.
17. For the unified user action, call
    `POST /proposals/{proposal_id}/approve-and-execute` from an Adapter
    administrator session (the acceptance profile's WordPress Application
    Password qualifies; signed key-pair clients are rejected with
    `npcink_openclaw_adapter_approve_requires_admin_session`).
    Confirm Adapter approved through Core when status was pending, ran Core
    commit-preflight, returned `proposal_id`, `post_id`, `ability_id`, and
    `correlation_id`, normalized ability input to `dry_run=false` and
    `commit=true`, returned an `execution_record`, and moved the test post to
    `trash`.
    Repeating the same execute or approve-and-execute request must return
    `npcink_openclaw_adapter_execution_already_completed` with the original
    `execution_record` and must not run the WordPress ability again.
    If execution fails after Core preflight has been consumed, Adapter must
    return a bounded public-safe `execution_record` with `status=failed`,
    `error_code`, failed action metadata, executed and failed counts, Core
    correlation fields, and `commit_execution=false`. Adapter does not store the full proposal or create a retry queue; OpenClaw should show the failure and require a new operator-reviewed proposal when retry is needed.
    For batch plan-shaped proposals, confirm `input.write_actions[]` executes
    only when every item targets the Adapter execution supported profiles and passes
    ability-specific input checks. Confirm the response includes
    `execution_mode=batch_write_actions`, `executed_count`, `failed_count`,
    and per-action `results[]`.
18. For lower-level approved proposal execution, use only the current
    `npcink-abilities-toolkit/trash-post`, `npcink-abilities-toolkit/create-draft`,
    `npcink-abilities-toolkit/update-post`, `npcink-abilities-toolkit/set-post-seo-meta`,
	`npcink-abilities-toolkit/set-post-slug`, `npcink-abilities-toolkit/set-post-terms`,
	`npcink-abilities-toolkit/delete-term`, `npcink-abilities-toolkit/update-media-details`,
	`npcink-abilities-toolkit/patch-post-content`,
		`npcink-abilities-toolkit/update-post-blocks`,
		`npcink-abilities-toolkit/update-template-blocks`,
		`npcink-abilities-toolkit/upsert-template-blocks`,
		`npcink-abilities-toolkit/update-template-part-blocks`,
		`npcink-abilities-toolkit/patch-setting-value`,
    `npcink-abilities-toolkit/replace-media-file`, `npcink-abilities-toolkit/restore-media-backup`,
    `npcink-abilities-toolkit/adopt-cloud-media-derivative`,
    `npcink-abilities-toolkit/rename-media-file`, `npcink-abilities-toolkit/delete-media-permanently`,
    `npcink-abilities-toolkit/reply-comment`, `npcink-abilities-toolkit/trash-comment`, or
    `npcink-abilities-toolkit/approve-comment` path and call
    `POST /proposals/{proposal_id}/execute`. Confirm Adapter performed Core
    preflight, passed `approval_context`, normalized ability input to
    `dry_run=false` and `commit=true`, returned `proposal_id`,
    `correlation_id`, and `ability_id`, and did not execute pending,
    dry-run-only, or preflight-failed proposals.
    Treat `patch-setting-value` as conditionally executable: confirm
    `execution_profile_readiness` reports the host-policy state, an
    unallowlisted target fails with
    `npcink_openclaw_adapter_setting_target_not_ready` before approval or
    commit-preflight, an allowlisted non-sensitive target can execute after
    Core approval, and a sensitive target is still rejected by Toolkit even if
    a host mistakenly includes it in the allowlist.
19. Confirm rejected proposals, non-supported proposals, and preflight-blocked
    proposals do not execute through approve-and-execute.
20. Pass `proposal_id` and `correlation_id` into later reads as query fields or
    as POST `/run-read-ability` `log_context`.
21. Do not call an Adapter provider/model smoke route; Adapter must not expose
    the removed provider log correlation smoke endpoint.
22. When a downstream AI client, Cloud runtime, or provider integration emits
    an AI Request Logs row under Adapter context, confirm it has `status=success`
    and context fields:
    `proposal_id`, `correlation_id`, `ability_id`, `adapter_request_id`,
    `adapter_route`, `ai_provider`, `ai_model`,
    `governance_source=npcink-governance-core`, and nested `npcink_governance_core`.
23. Confirm correlation in:
    - Core Governance Audit, filtered by `proposal_id` or `correlation_id`;
    - AI Request Logs, using Adapter context fields.

## Example Commands

Health:

```bash
curl -sS --user "OPENCLAW_USERNAME:<openclaw-secret-field-value>" \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/health"
```

Capabilities:

```bash
curl -sS --user "OPENCLAW_USERNAME:<openclaw-secret-field-value>" \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/capabilities"
```

Direct read:

```bash
curl -sS --user "OPENCLAW_USERNAME:<openclaw-secret-field-value>" \
  -H "Content-Type: application/json" \
  -d '{"ability_id":"npcink-abilities-toolkit/site-info","input":{}}' \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/run-read-ability"
```

Diagnostics read:

```bash
curl -sS --user "OPENCLAW_USERNAME:<openclaw-secret-field-value>" \
  -H "Content-Type: application/json" \
  -d '{"ability_id":"npcink-abilities-toolkit/wp-ops-diagnostics-detail","input":{"include_active_plugins":true,"include_inactive_plugins":false,"include_plugin_updates":true,"include_must_use_plugins":true,"include_dropins":true,"include_log_contents":false}}' \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/run-read-ability"
```

Create proposal:

```bash
curl -sS --user "OPENCLAW_USERNAME:<openclaw-secret-field-value>" \
  -H "Content-Type: application/json" \
  -d '{"ability_id":"npcink-abilities-toolkit/create-draft","title":"OpenClaw draft acceptance","summary":"OpenClaw requests a governed draft proposal during acceptance.","input":{"title":"OpenClaw acceptance draft","dry_run":true,"commit":false},"preview":{"dry_run":true,"commit":false},"caller":{"external_thread_id":"OPENCLAW_ACCEPTANCE_THREAD"}}' \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/proposals"
```

Create proposals from a read-only plan:

```bash
curl -sS --user "OPENCLAW_USERNAME:<openclaw-secret-field-value>" \
  -H "Content-Type: application/json" \
  -d '{"plan_ability_id":"npcink-abilities-toolkit/build-content-inventory-fix-plan","plan":{"batch_id":"acceptance","issue_types":[],"requires_approval":true,"commit_execution":false,"dry_run":true,"action_count":0,"write_actions":[],"preview":[],"risk":{"level":"medium"}},"plan_input":{"per_page":1},"caller":{"external_thread_id":"OPENCLAW_ACCEPTANCE_THREAD"}}' \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/proposals/from-plan"
```

Query proposal status:

```bash
curl -sS --user "OPENCLAW_USERNAME:<openclaw-secret-field-value>" \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/proposals/PROPOSAL_ID"
```

Commit preflight after Core approval:

```bash
curl -sS --user "OPENCLAW_USERNAME:<openclaw-secret-field-value>" \
  -X POST \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/proposals/PROPOSAL_ID/commit-preflight"
```

Correlation read:

```bash
curl -sS --user "OPENCLAW_USERNAME:<openclaw-secret-field-value>" \
  -H "Content-Type: application/json" \
  -d '{"ability_id":"npcink-abilities-toolkit/site-info","input":{},"log_context":{"proposal_id":"PROPOSAL_ID","correlation_id":"CORRELATION_ID"}}' \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/run-read-ability"
```

## Expected Failure Behavior

OpenClaw must stop and report the reason when Adapter or Core returns:

- `401` for missing or invalid WordPress REST authentication;
- `403` for missing WordPress capability or disabled approval proxy;
- `404` for missing proposal;
- `429` when Core app-key rate policy rejects an internal Core request;
- `npcink_openclaw_adapter_proposal_required` when a caller tries to execute a
  proposal-required ability as a direct read;
- `npcink_openclaw_adapter_execute_profile_unsupported` when a caller tries to approve or
  reject through Adapter's standalone generic approval proxy routes;
- `npcink_openclaw_adapter_execute_profile_unsupported` when the proposal ability is
  outside Adapter's execution supported profiles;
- `npcink_openclaw_adapter_write_action_invalid`,
  `npcink_openclaw_adapter_write_action_target_required`,
  `npcink_openclaw_adapter_write_actions_limit_exceeded`, or
  `npcink_openclaw_adapter_post_id_required` when a batch write action does not
  satisfy Adapter's V1 execution input contract;
- `npcink_openclaw_adapter_proposal_rejected` when approve-and-execute is attempted
  after Core rejection;
- `npcink_openclaw_adapter_preflight_not_authorized` or
  `npcink_openclaw_adapter_preflight_item_blocked` when Core commit-preflight blocks
  execution.

These failure responses should include additive `data.operator_feedback` for
operator-facing revision loops. The object must preserve Core evidence without
becoming a second approval truth: `status`, `message`, `reasons[]`,
`revision_fields[]`, `next_steps[]`, `can_retry_after_revision`, and
`core_evidence`. OpenClaw should display it, stop execution, and guide the
operator to revise the plan or draft before creating a new proposal.

The disabled approval and rejection stubs are part of the acceptance surface.
They prove that OpenClaw can discover the routes while using
`approve-and-execute` from an administrator session for the unified user
action or Core admin for split approval decisions.

## Non-Goals

This acceptance pass must not add or require:

- generic Adapter approval or rejection proxying;
- final WordPress write execution outside the current supported
  approve-and-execute path;
- Core `/execute` or `/proxy-execute`;
- MCP runtime;
- workflow runtime, queues, retries, or schedulers;
- provider credential storage;
- prompt, preset, model router, or product workflow ownership in Adapter.

## Verification Gates

After changing Adapter, run:

```bash
composer test:all
composer plugin-check:release
composer package:release
composer smoke:wp
git diff --check
```

For Gutenberg page or article block changes, `composer smoke:wp` must exercise
the full `plan -> proposal -> approve-and-execute -> get-post-blocks` path and
fail if generated content contains broken images, blank headings/paragraphs, no
section spacing signal, or missing Adapter readback verification.
Run `composer visual:wp` when front-end layout quality is in scope; it retains
the smoke fixtures, opens the front-end URLs at desktop/tablet/mobile viewports,
checks image loading, horizontal overflow, visible headings, controls, and
section spacing, then writes screenshots plus `build/visual-acceptance/report.json`.

After changing Core cross-reference docs, run:

```bash
composer test:all
git diff --check
```

Run `composer smoke:wp` in Core only when Core behavior changes. A docs-only
Core cross-reference does not need a Core WordPress smoke pass.

## Completion Criteria

OpenClaw consumer acceptance is complete when:

- health, help, capabilities, direct read, diagnostics read, proposal create,
  plan-to-proposal forwarding, proposal list/detail, disabled approve/reject
  stubs, unified approve-and-execute, split Core admin approval/rejection, and
  commit preflight are all verified through Adapter;
- Core audit and AI Request Logs can be correlated with `proposal_id` or
  `correlation_id`;
- Core Governance Audit remains the governance log, and WordPress `ai` plugin
  AI Request Logs remain the provider request log;
- Adapter does not expose a provider/model smoke route, and downstream provider
  integrations can still record explicit `ai_provider` and explicit `ai_model`
  in AI Request Logs context when they run under Adapter/Core correlation;
- Gutenberg page and article recipes expose visual acceptance metadata, and any
  browser pass uses the shared
  [`openclaw-gutenberg-visual-acceptance.md`](openclaw-gutenberg-visual-acceptance.md)
  checklist rather than Adapter-side rendering logic;
- visually rich Pattern pages use the two-stage visual-asset recipe so reviewed
  media adoption happens before `pattern_page_plan` receives
  `variables.hero_media_url`;
- research-backed Pattern pages use `pattern_page_research_brief` as
  suggestion-only evidence before choosing page variables, section variants,
  visual assets, and proof angles;
- Adapter does not publish standalone approval or rejection routes
  proposal state;
- plan `write_actions` are executed only through the accepted supported batch
  policy after Core approval and preflight;
- `skipped_destructive_candidates` are never reported as Adapter-executed
  mutations;
- `commit_execution=false` remains true at preflight;
- no new runtime ownership is added to Core, Adapter, or Abilities.
