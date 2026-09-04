# OpenClaw Adapter Contract

Status: initial productization contract.

Npcink OpenClaw Adapter gives OpenClaw a focused WordPress REST surface without
turning Npcink Governance Core into an execution proxy.

The productized acceptance checklist lives in
[`openclaw-consumer-acceptance.md`](openclaw-consumer-acceptance.md). Keep this
contract as the boundary definition and use the acceptance checklist for
OpenClaw connection verification.

OpenClaw only connects to Adapter. Core is Adapter's governance service for
proposal storage, approval status, commit preflight, and audit attribution.
Adapter may expose the productized OpenClaw user action for
approve-and-execute, but Core remains the governance truth source behind that
action.

## Dependencies

- WordPress 7.0+ with WordPress Abilities API routes available.
- PHP 8.0+.
- `npcink-abilities-toolkit` for canonical ability definitions and callbacks.
- `npcink-governance-core` for governance, proposal approval, commit preflight, and
  audit.

## Machine-Readable Contract Metadata

`GET /health`, `GET /help`, and `GET /connection/manifest` expose a shared
`contract` object. Adapter contract version `4` includes:

- Adapter/client policy/registry versions and stable hashes for execution
  profiles, supported execute ability ids, and supported plan ability ids;
- `core_proxy_execute=false`;
- `commit_execution=false`;
- the generic `client_contract=generic_ai_client` product identity with
  `priority_channel=openclaw` and the retained compatibility namespace;
- `workflow_projection`, which points clients to Toolkit-owned definition
  discovery, forbids Adapter definition/runtime storage, and lists parity
  fields that fail closed on version mismatch;
- Adapter-declared compatibility floors for Governance Core and Abilities
  Toolkit: `core_contract_min_version`, `core_plugin_min_version`,
  `toolkit_contract_min_version`, and `toolkit_plugin_min_version`.

The compatibility floors are Adapter declarations for client-side drift
detection. They are not Core-emitted or Toolkit-emitted runtime proofs, and they
do not authorize reads or writes. Core approval, read-preflight,
commit-preflight, and WordPress Abilities execution remain the authoritative
runtime checks.

When the dependency plugins provide their admin-only contract endpoints, the
same three Adapter surfaces also expose `dependency_contracts` and
`dependency_contracts_ready`. Adapter reads
`/npcink-governance-core/v1/contract` and
`/npcink-abilities-toolkit/v1/contract`, checks
`npcink_governance_core_contract.v1` and
`npcink_abilities_toolkit_contract.v1` against the declared floors, and returns
only a bounded compatibility summary. The Core summary includes boundary fields
such as `core_proxy_execute=false`, `commit_execution=false`,
`provider_secret_storage=false`, the declared final-write authority, Core's
implementation posture metadata contract, and whether Core's contract advertises
fail-closed site and signed-client fingerprint bindings for `approval_context`,
`execution_handoff`, and `read_authorization_context`. Adapter treats those Core
binding and posture fields as part of dependency readiness, not as a new source
of approval truth. The Toolkit summary includes ability/hash fingerprints and
write controls such as `host_governed_writes=true`, `dry_run_default=true`, and
`commit_default=false`. It also treats Toolkit's official WordPress Abilities
API alignment fields as dependency readiness signals:
`ability_catalog_source=wordpress_abilities_api`,
`input_schema_source=wordpress_abilities_api`,
`output_schema_source=wordpress_abilities_api`,
`callback_free_hashes=true`, `stable_contract_hashes=true`,
`read_execution_surface=wordpress_abilities_api`, and
`write_execution_surface=host_runtime_after_governance`. Adapter only carries
those bounded booleans, strings, and hashes. It must not copy raw ability definitions into Adapter, nor copy callbacks, permission callables, approval records,
audit records, provider secrets, prompt material, model routing, runtime state,
or Cloud execution truth from Toolkit.

`dependency_contracts` is a runtime proof complement to the version floors, not
a new source of truth. It must not include Core proposal bodies, approval
records, audit timelines, ability callback internals, provider credentials, or
raw secrets. If the current REST authentication context cannot read a dependency
contract endpoint, Adapter reports that dependency summary as unavailable rather
than elevating privileges.

When Core approval, commit-preflight, or sensitive read authorization contexts
include optional `site_url`, `home_url`, or `blog_id` bindings, Adapter verifies
those fields against the current WordPress site before executing the handoff or
authorized read. Adapter also forwards the authenticated local client key
fingerprint to trusted Core app-token requests; when Core returns
`signed_client_fingerprint` or the compatible `client_key_fingerprint` alias,
Adapter verifies it against the current signed local client. A mismatch fails
closed with the matching `npcink_openclaw_adapter_preflight_*_mismatch`,
`npcink_openclaw_adapter_preflight_handoff_*_mismatch`,
`npcink_openclaw_adapter_core_read_grant_*_mismatch`, or
`*_signed_client_fingerprint_mismatch` error.

## Read Ability Contract

The adapter may execute only capability rows where Core returns:

```json
{
  "governance_mode": "direct_read",
  "execution_surface": "wp_abilities_rest",
  "read_policy": "direct_read_public",
  "sensitivity": "public",
  "redaction_required": false,
  "core_proxy_execute": false,
  "commit_execution": false
}
```

The adapter executes those reads through:

```text
/wp-json/wp-abilities/v1/abilities/{ability_id}/run
```

The read path does not execute abilities marked `proposal_required`.
When Core reports `read_policy=core_read_authorization_required` or
`read_authorization_required=true`, Adapter returns
`npcink_openclaw_adapter_core_read_authorization_required` and requires the
caller to complete Core's read-request/grant flow first.

Every successful read response is an Adapter read envelope. It includes:

- `ability_id`
- `governance_mode=direct_read`
- `execution_surface=wp_abilities_rest`
- `read_policy`
- `sensitivity`
- `redaction_required`
- `redaction_applied`
- `redaction_summary`
- `read_audit_mode`
- `correlation_id`
- `log_context`
- `read_context`
- `commit_execution=false`
- `result`

For `direct_read_sensitive` rows or any row with
`redaction_required=true`, Adapter applies bounded recursive redaction before
returning `result`. It redacts values under sensitive keys such as passwords,
secrets, tokens, authorization headers, cookies, nonces, email fields, and API
or private keys. This is a product-surface redaction layer; Core remains the
capability guidance source and WordPress Abilities API remains the canonical
ability execution surface.

If Core marks a capability as requiring explicit sensitive read authorization,
Adapter must fail closed unless the caller supplies an approved Core read
request id for the same ability and input. The current Adapter recognizes any
of these Core capability signals:

```json
{
  "read_authorization_required": true,
  "requires_read_authorization": true,
  "read_policy": "core_read_authorization_required",
  "authorization_mode": "core_read_request"
}
```

For nested guidance, Adapter also treats
`read_authorization.required=true` as a Core-managed sensitive read gate. The
default response code is
`npcink_openclaw_adapter_core_read_authorization_required` with
`required_flow=core_read_request`.

OpenClaw creates the request through Adapter `POST /read-requests`, waits for
Core approval, and then calls `POST /run-read-ability` with the same `ability_id`,
same `input`, and `read_request_id`. Adapter calls Core
`/read-requests/{request_id}/read-preflight` immediately before executing the
read, verifies `read_authorization_granted=true`,
`core_authorization_truth=npcink_governance_core`, `ability_id`,
`approved_input_hash`, expiry, and `commit_execution=false` /
`write_execution=false`, then applies Core `bounds` and `redaction_level` before
returning `result`.

This remains a fail-closed boundary without a valid Core grant. Adapter must not
treat OpenClaw prompts, chat instructions, direct database access, filesystem
reads, logs, or custom scripts as substitutes for Core read authorization.

## Media Derivative Cloud Boundary

Adapter does not expose media derivative Cloud orchestration routes. There is
no Adapter-owned `/media-derivative-runs`, artifact preview proxy, or derivative
proposal-payload route. Cloud Addon owns Cloud run/result/artifact transport.
Toolkit/Core plan abilities own request and proposal payload shaping.

Adapter may expose proposal-specific media optimization readiness and may
execute the explicit post-Core `npcink-abilities-toolkit/adopt-cloud-media-derivative`
profile after Core approval and commit preflight. It must not store run truth,
artifact truth, Cloud credentials, approval truth, or media registry state.
For OSS/CDN/object-storage backed attachments, Adapter only forwards Toolkit
storage preflight evidence and drift guards such as
`expected_storage_provider`, `expected_storage_adapter`, and
`storage_preflight` after Core approval. Adapter does not own object-storage
credentials, SDK calls, signed source URLs, uploads, restores, or cache purges;
Toolkit/host storage adapters must fail closed when the current media storage
state is not writable through the approved ability path.

The returned payloads must preserve:

- `final_write_owner=local_wordpress_host`;
- `wordpress_write_included=false`;
- `attachment_metadata_write_included=false`;
- `commit_execution=false`.

Any recording, attachment metadata update, media replacement, or rollback must
enter Core proposal governance and pass Core approval plus commit-preflight
before Adapter's supported final execution path can run.

## Read-Only Planning Contract

The adapter may execute these planning abilities only when Core reports them as
direct reads:

- `npcink-abilities-toolkit/build-content-inventory-fix-plan`
- `npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan`
- `npcink-abilities-toolkit/build-media-inventory-fix-plan`
- `npcink-abilities-toolkit/build-media-reference-repair-plan`
- `npcink-abilities-toolkit/build-media-settings-reference-repair-plan`
- `npcink-abilities-toolkit/build-media-optimization-plan`
- `npcink-abilities-toolkit/build-media-rename-plan`
- `npcink-abilities-toolkit/build-article-optimization-apply-plan`
- `npcink-abilities-toolkit/build-media-alt-apply-plan`
- `npcink-abilities-toolkit/build-block-theme-site-plan`
- `npcink-abilities-toolkit/build-pattern-page-plan`
- `npcink-toolbox/build-article-write-plan`
- `npcink-toolbox/build-article-batch-write-plan`
- `npcink-toolbox/build-article-media-batch-write-plan`
- `npcink-toolbox/build-image-candidate-adoption-plan`
- `npcink-toolbox/build-site-knowledge-review-plan`
- `npcink-toolbox/build-nightly-inspection-review-plan`

OpenClaw may also use direct read execution for media format inspection:

- `npcink-abilities-toolkit/inspect-media-asset`

Planning ability outputs are plan data, not execution results. Adapter must
preserve `batch_id`, `issue_types`, `post_ids`, `attachment_ids`,
`write_actions`, `preview`, `risk`, `requires_approval`, `commit_execution`,
`dry_run`, `manual_review`, `skipped_destructive_candidates`, `issue_counts`, and
`action_count`.

For Toolbox article writing, `npcink-toolbox/build-article-write-plan`
returns a reviewed `article_write_plan`. Adapter may forward that plan to Core,
but Core validates readiness, risk, blocked claims, and draft-only
`npcink-abilities-toolkit/create-draft` intent before any proposal can be approved or
executed.

For Toolbox article batch writing,
`npcink-toolbox/build-article-batch-write-plan` returns a reviewed
`article_batch_write_plan`. Adapter may forward that plan to Core only as a
batch proposal handoff; each executable action must still target the
`npcink-abilities-toolkit/create-draft` execution profile, keep `status=draft`, and pass Core
approval plus commit-preflight before Adapter execution.

For Toolbox article media batch writing,
`npcink-toolbox/build-article-media-batch-write-plan` returns a reviewed
`article_media_batch_write_plan`. Adapter may forward that plan to Core only as
a batch proposal handoff; each executable action must still target an explicit
Adapter profile such as `npcink-abilities-toolkit/create-draft`,
`npcink-abilities-toolkit/upload-media-from-url`, `npcink-abilities-toolkit/update-media-details`, or
`npcink-abilities-toolkit/set-post-featured-image`, and pass Core approval plus
commit-preflight before Adapter execution.

For Toolbox image candidate adoption,
`npcink-toolbox/build-image-candidate-adoption-plan` returns a reviewed
`image_candidate_adoption_plan` from one normalized `image_candidate.v1`
candidate. Adapter may forward that plan to Core only as a batch proposal
handoff; each executable action must still target an explicit Adapter profile
such as `npcink-abilities-toolkit/upload-media-from-url`,
`npcink-abilities-toolkit/update-media-details`, or
`npcink-abilities-toolkit/set-post-featured-image`, and pass Core approval plus
commit-preflight before Adapter execution.

For Toolbox Site Knowledge review,
`npcink-toolbox/build-site-knowledge-review-plan` returns a blocked
`site_knowledge_review_plan` from evidence-backed Cloud Site Knowledge agent
handoff data. Adapter may forward that plan to Core only as a review proposal
handoff. It must remain `proposal_ready=false`, require human `title` and
`content` input, and must not approve, preflight, execute, or write WordPress
content.

For Toolkit block theme site planning,
`npcink-abilities-toolkit/build-block-theme-site-plan` returns a reviewed
`block_theme_site_plan` for conversational Site Editor changes. Adapter may
forward that plan to Core only as a batch proposal handoff; each executable
action must still target `npcink-abilities-toolkit/update-template-blocks`,
`npcink-abilities-toolkit/upsert-template-blocks`, or
`npcink-abilities-toolkit/update-template-part-blocks`, and pass Core approval
plus commit-preflight before Adapter execution. Global styles, navigation,
template creation, and arbitrary Site Editor writes are not part of this MVP.

## OpenClaw Research Atomics

OpenClaw may use four research atom aliases for Zhihu and trusted-search
article preparation:

- `openclaw_atoms.zhihu_hot_topics`
- `openclaw_atoms.zhihu_search`
- `openclaw_atoms.global_search`
- `openclaw_atoms.zhida_answer`

These aliases call the canonical Toolbox ability
`npcink-toolbox/cloud-web-search` through
`POST /wp-json/npcink-openclaw-adapter/v1/run-read-ability` with a
`managed_source` such as `zhihu_hot_topics`, `zhihu_research`,
`zhihu_global_search`, `zhida_simple`, `zhida_deep`, or
`zhida_deepsearch`.

The atoms are direct-read research inputs only. They may be composed by
OpenClaw as `openclaw.article_research_pack`, but Adapter must not expose an
Adapter-owned research recipe catalog, `/cloud/*` route, Cloud signing client,
provider credential store, prompt runtime, usage counter, cache truth, or
article generation surface. The output must remain
`write_posture=suggestion_only`, `direct_wordpress_write=false`, and
`final_write_path=core_proposal_required`.

See
[`openclaw-zhihu-research-atomics.md`](openclaw-zhihu-research-atomics.md) for
the atom input contracts, output artifact expectations, and boundary rules.

## OpenClaw Recipe Discovery

`GET /help` includes `openclaw_recipes.article_draft_plan` for clients that need
a machine-readable fixed flow. The recipe is channel guidance only:

- entrypoint ability: `npcink-toolbox/build-article-write-plan`
- plan handoff route: `POST /proposals/from-plan`
- status route: `GET /proposals/{proposal_id}`
- final route: `POST /proposals/{proposal_id}/approve-and-execute`
- final write ability: `npcink-abilities-toolkit/create-draft`

The recipe must keep `core_proxy_execute=false`,
`commit_execution=false`, `draft_only=true`, and `publish_allowed=false`.
Adapter does not become an article workflow runtime or a Cloud control plane.

Toolbox may expose click-driven buttons for the same fixed flows that Adapter
publishes to OpenClaw. Those buttons must mirror the same ability ids, artifact
types, and Core proposal handoff routes; they do not make Toolbox a second
OpenClaw recipe owner, proposal truth, approval surface, or write executor.

`GET /help` also includes `openclaw_recipes.article_batch_draft_plan` for
reviewed 2-5 article draft batches:

- entrypoint ability: `npcink-toolbox/build-article-batch-write-plan`
- plan handoff route: `POST /proposals/from-plan`
- status route: `GET /proposals/{proposal_id}`
- final route: `POST /proposals/{proposal_id}/approve-and-execute`
- final write ability: `npcink-abilities-toolkit/create-draft`
- artifact type: `article_batch_write_plan`
- proposal mode: `batch`

The batch recipe must keep `batch_approval=true`, declare
`atomicity=non_atomic` and `partial_success_possible=true` (execution can
stop after earlier actions have succeeded), `core_proxy_execute=false`,
`commit_execution=false`, `draft_only=true`, and `publish_allowed=false`.

`GET /help` also includes `openclaw_recipes.article_media_batch_plan` for
reviewed article drafts with selected image-source candidates:

- entrypoint ability:
  `npcink-toolbox/build-article-media-batch-write-plan`
- plan handoff route: `POST /proposals/from-plan`
- status route: `GET /proposals/{proposal_id}`
- final route: `POST /proposals/{proposal_id}/approve-and-execute`
- final write abilities: `npcink-abilities-toolkit/create-draft`,
  `npcink-abilities-toolkit/upload-media-from-url`, `npcink-abilities-toolkit/update-media-details`, and
  `npcink-abilities-toolkit/set-post-featured-image`
- artifact type: `article_media_batch_write_plan`
- proposal mode: `batch`

The article media batch recipe must preserve image-source attribution and keep
`batch_approval=true`, declare `atomicity=non_atomic` and
`partial_success_possible=true`, `core_proxy_execute=false`,
`commit_execution=false`, `draft_only=true`, and `publish_allowed=false`.

`GET /help` also includes `openclaw_recipes.content_intent_router` for routing
natural-language content requests to one supported Gutenberg recipe before a
plan is built:

- entrypoint ability:
  `npcink-abilities-toolkit/route-content-intent`
- artifact type: `content_intent_route`
- `prompt_is_authorization=false`
- default behavior: `fail_closed`
- supported routes: `page_landing`, `post_article`, and
  `site_template_breadcrumbs`
- downstream routes: `pattern_page_plan`, `article_block_plan`, and
  `block_theme_site_plan`

The content intent router is read-only. It must not emit `write_actions`, must
not create proposals for `route=unsupported`, and must not treat a customer
prompt as execution approval. See
[`docs/openclaw-content-intent-router-contract.md`](openclaw-content-intent-router-contract.md).

`GET /help` also includes `openclaw_recipes.pattern_page_plan` for reviewed
Gutenberg page pattern drafts:

- entrypoint ability:
  `npcink-abilities-toolkit/build-pattern-page-plan`
- plan handoff route: `POST /proposals/from-plan`
- status route: `GET /proposals/{proposal_id}`
- final route: `POST /proposals/{proposal_id}/approve-and-execute`
- final write abilities: `npcink-abilities-toolkit/create-draft` and
  `npcink-abilities-toolkit/update-post-blocks`
- artifact type: `pattern_page_plan`
- proposal mode: `batch`

The Toolkit owns pattern registry, whitelisted classes, and Gutenberg block
rendering. Adapter must only forward the plan to Core and execute supported
write actions after Core approval and commit-preflight.
The recipe also exposes `visual_acceptance` so OpenClaw can run browser checks
against the created draft page without treating Adapter as a browser runner.

`GET /help` also includes `openclaw_recipes.site_edit_router` for normalizing
untrusted customer wording before a reviewed block-editing recipe is selected:

- contract mode: `untrusted_user_prompt_to_allowed_recipe`
- `prompt_is_authorization=false`
- default behavior: `fail_closed`
- supported routes: `article_block_plan`, `pattern_page_plan`, and
  `block_theme_site_plan`
- fail-closed surfaces: navigation, global styles, raw theme files, raw template
  HTML, direct database writes, auto-approval, and direct execution

The router is a machine-readable contract, not a prompt owner or workflow
runtime. Adapter must not execute customer natural language directly. It may only
project the allowed surface/intent/target shape and then continue through the
existing recipe, plan, proposal, approval, commit-preflight, execution profile,
and read-back verification path.

`GET /help` also includes `openclaw_recipes.block_theme_site_plan` for reviewed
conversational block theme Site Editor changes:

- context abilities:
  `npcink-abilities-toolkit/get-block-theme-context`,
  `npcink-abilities-toolkit/inspect-block-theme-surface`,
  `npcink-abilities-toolkit/inspect-gutenberg-composition-contract`,
  `npcink-abilities-toolkit/get-template-blocks`, and
  `npcink-abilities-toolkit/get-template-part-blocks`
- inspection ability:
  `npcink-abilities-toolkit/inspect-block-theme-surface`
- lightweight contract inspection ability:
  `npcink-abilities-toolkit/inspect-gutenberg-composition-contract`
- entrypoint planning ability, only when inspection recommends a fix:
  `npcink-abilities-toolkit/build-block-theme-site-plan`
- plan handoff route: `POST /proposals/from-plan`
- status route: `GET /proposals/{proposal_id}`
- final route: `POST /proposals/{proposal_id}/approve-and-execute`
- final write abilities: `npcink-abilities-toolkit/update-template-blocks`,
  `npcink-abilities-toolkit/upsert-template-blocks`, and
  `npcink-abilities-toolkit/update-template-part-blocks`
- artifact type: `block_theme_site_plan`
- proposal mode: `batch`

The Toolkit owns block theme context reads, surface inspection, lightweight
composition contract inspection, Site Editor entity block planning, and final
WordPress Abilities write callbacks. Adapter must only forward plans with
reviewed `write_actions[]` to Core and execute supported write actions after
Core approval and commit-preflight. After execution or when the user asks to
check the result, clients should read back the template blocks and call
`npcink-abilities-toolkit/inspect-gutenberg-composition-contract`; only
`contract_status=needs_revision` should trigger another supported plan. The MVP
supports `intent=add_breadcrumbs` plus bounded
`intent=customize_template_layout` profiles, and keeps global styles,
navigation, raw template HTML, and arbitrary unprofiled template composition
outside the execution profile.

`GET /help` also includes `openclaw_recipes.article_block_plan` for reviewed
Gutenberg article block drafts:

- entrypoint ability:
  `npcink-abilities-toolkit/build-article-block-plan`
- plan handoff route: `POST /proposals/from-plan`
- status route: `GET /proposals/{proposal_id}`
- final route: `POST /proposals/{proposal_id}/approve-and-execute`
- final write abilities: `npcink-abilities-toolkit/create-draft` and
  `npcink-abilities-toolkit/update-post-blocks`
- artifact type: `article_block_plan`
- proposal mode: `batch`

The Toolkit owns editorial templates, native Gutenberg article block rendering,
and responsive quality metadata. Adapter must only forward the plan to Core and
execute supported write actions after Core approval and commit-preflight.
The recipe also exposes `visual_acceptance` with the same front-end/editor
targets and viewport set used by the pattern page flow.

The adapter plan-to-proposal bridge also supports reviewed article optimization
apply plans from Toolkit:

- entrypoint ability:
  `npcink-abilities-toolkit/build-article-optimization-apply-plan`
- source recipe: `npcink-abilities-toolkit/recipes/article-optimization`
- plan handoff route: `POST /proposals/from-plan`
- status route: `GET /proposals/{proposal_id}`
- final route: `POST /proposals/{proposal_id}/approve-and-execute`
- final write ability: `npcink-abilities-toolkit/update-post`
- artifact type: `article_optimization_apply_plan`
- proposal mode: `single`

Adapter forwards the reviewed apply plan to Core and keeps direct WordPress
writes disabled in the plan phase. Execution remains gated by Core approval and
Adapter commit-preflight.

The shared browser checklist lives in
[`openclaw-gutenberg-visual-acceptance.md`](openclaw-gutenberg-visual-acceptance.md).

`GET /help` also includes `openclaw_recipes.pattern_page_research_brief` for
research-backed Gutenberg landing pages:

- entrypoint ability:
  `npcink-toolbox/build-content-discoverability-brief`
- external search intent: `competitor_research`
- research projection: `landing_page_research_brief`
- default max reference sites: `5`
- artifact posture: `suggestion_only`

Cloud owns search providers, provider keys, usage metering, evidence retrieval,
reader enhancement, and failure diagnostics. Adapter only exposes the bounded
search intent and guardrails. The research brief must not copy reference-site
text, images, CSS, pricing claims, customer claims, rankings, or unsupported
feature claims into the Pattern page plan.

`GET /help` also includes `openclaw_recipes.image_candidate_adoption_plan` for
reviewed adoption of one image candidate into the media library:

- entrypoint ability:
  `npcink-toolbox/build-image-candidate-adoption-plan`
- candidate contract: `image_candidate.v1`
- plan handoff route: `POST /proposals/from-plan`
- status route: `GET /proposals/{proposal_id}`
- final route: `POST /proposals/{proposal_id}/approve-and-execute`
- final write abilities: `npcink-abilities-toolkit/upload-media-from-url`,
  `npcink-abilities-toolkit/update-media-details`, and optional
  `npcink-abilities-toolkit/set-post-featured-image`
- artifact type: `image_candidate_adoption_plan`
- proposal mode: `batch`

The image candidate adoption recipe must preserve source attribution and keep
`batch_approval=true`, `core_proxy_execute=false`,
`commit_execution=false`, and `cloud_control_plane=false`. Adapter does not
search stock providers, generate images, upload media, set featured images, or
create a media registry by itself.

`GET /help` also includes
`openclaw_recipes.pattern_page_with_visual_asset_plan` for visually richer
Gutenberg landing pages. This is a composed two-stage playbook that can follow
`pattern_page_research_brief`:

1. ask the Cloud-backed image source recommender for reviewable
   `image_candidate.v1` options;
2. use hosted AI generation only as a fallback when no recommended candidate
   matches the page visual brief;
3. crop and convert the selected candidate through the Cloud media derivative
   path before adoption;
4. use `npcink-toolbox/build-image-candidate-adoption-plan` or
   `npcink-abilities-toolkit/build-media-adoption-enhancement-plan` to ask Core
   for media adoption;
5. pass the approved local WordPress media URL into
   `npcink-abilities-toolkit/build-pattern-page-plan` with
   `media_strategy=existing_media_url`.

The composed playbook keeps `hosted_generation_candidate_only=true`,
`cloud_candidate_selection_allowed=true`,
`hosted_ai_generation_allowed_as_fallback=true`,
`cloud_crop_required_before_page_plan=true`,
`candidate_review_required=true`, `core_proxy_execute=false`,
`commit_execution=false`, `cloud_control_plane=false`, and
`generic_write_executor=false`. Adapter must not call hosted generation,
import media, crop images, or create the page as one direct mutation. The page
plan must reference the final local WordPress media URL, not a remote source or
temporary Cloud preview URL.

`GET /help` also includes
`openclaw_recipes.ai_image_ratio_crop_media_adoption` for AI-generated images
that need a stable page-slot ratio before adoption. This is a composed
candidate-crop-adoption playbook:

1. collect and review an `image_candidate.v1` from Cloud recommendation first;
2. use hosted generation only when recommendation fails to produce a
   reviewable fit;
3. treat requested generation dimensions as advisory, then use Cloud Addon or
   approved Cloud tooling to run a bounded crop from a local `attachment_id` or
   same-site `source_artifact`;
4. use the cropped preview URL immediately as input to
   `npcink-abilities-toolkit/build-media-adoption-enhancement-plan`;
5. submit the returned plan to Core through `POST /proposals/from-plan`.

The playbook keeps `target_aspect_ratio_required=true`,
`ai_generation_dimensions_are_advisory=true`,
`cloud_recommendation_precedes_generation=true`,
`cloud_crop_required_for_generated_images=true`,
`candidate_review_required=true`, `signed_preview_is_temporary=true`,
`core_proxy_execute=false`, `commit_execution=false`,
`cloud_control_plane=false`, `adapter_artifact_registry=false`, and
`direct_wordpress_write=false`. Adapter must not crop arbitrary remote URLs,
store Cloud artifacts, or make the cropped preview the canonical page media URL.

`commit_execution=false` means no write happened, `dry_run=true` means preview
only, and `requires_approval=true` means the plan must be handed to Core or the
host governance layer. Adapter must not execute, approve, or promote
destructive candidates such as `npcink-abilities-toolkit/delete-media-permanently`,
`npcink-abilities-toolkit/delete-post-permanently`, `npcink-abilities-toolkit/delete-term`,
`npcink-abilities-toolkit/trash-post`, `npcink-abilities-toolkit/trash-comment`, or
`npcink-abilities-toolkit/spam-comment`.

## Governed Write Contract

For write or destructive abilities, the adapter relays to Core:

```text
POST /wp-json/npcink-governance-core/v1/proposals
POST /wp-json/npcink-governance-core/v1/proposals/from-plan
POST /wp-json/npcink-governance-core/v1/proposals/{proposal_id}/commit-preflight
```

The adapter does not store proposal governance state. It may call Core approval
only as part of the explicit unified approve-and-execute action.

Failure responses for plan intake, rejected proposals, and commit-preflight
blocks may include additive `data.operator_feedback`. This is an OpenClaw
display contract, not an Adapter approval store. It summarizes Core or Adapter
evidence as `status`, `severity`, `message`, `reasons[]`,
`revision_fields[]`, `next_steps[]`, `can_retry_after_revision`, and
`core_evidence` so the operator can revise the source plan or draft and create
a new proposal.

`POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/commit-preflight`
is an advanced diagnostic Adapter route. Core handoffs are one-time; when this
route succeeds through Adapter, Adapter stores only a bounded handoff cache for
the next Adapter execute request for the same approved proposal input. OpenClaw
must not call Core commit-preflight directly and then ask Adapter to execute the
same proposal.

Dry-run-only proposal verification stops at Adapter commit-preflight. Adapter `execute`, `execute-approved-proposal`, and `approve-and-execute` routes are final write paths; immediately before dispatching a WordPress ability, Adapter normalizes the ability input to `dry_run=false` and `commit=true`. OpenClaw must not call an execute route when the operator only asked to verify a dry-run proposal or preflight.

Before Adapter calls the WordPress Abilities API for a final supported write,
it must verify Core's `approval_context.approved_input_hash` matches the current
proposal input hash and that `approval_context.policy_version` is
`core-preflight-v1`. Core must also return an `execution_handoff` for the same
approved input and policy. Adapter executes only when that handoff has
`executor=adapter_after_core_preflight`, `execution_surface=wp_abilities_rest`,
`core_proxy_execute=false`, `commit_execution=false`, the same `proposal_id`,
the same commit-preflight `correlation_id`, and an `ability_id` matching either
the proposal ability or one of the approved `write_actions[]` target abilities.
Both `approval_context.expires_at` and `execution_handoff.expires_at` must be
present and still valid. When Core includes `signed_client_fingerprint` or
`client_key_fingerprint`, the value must match the currently authenticated local
client key. Mismatches fail closed; Adapter must not repair, re-approve, or
execute the proposal.

When Core capability discovery declares an ability `implementation_posture`,
Adapter must also validate that posture before final execution. Accepted posture
is metadata-only, dry-run-first, host-governed, and must declare Core as
approval/audit/final authorization owner while keeping `commit_default=false`
and `direct_wordpress_write_default=false`. If the declared posture enables
runtime, scheduler, model routing, provider credential, approval storage, or
audit storage ownership inside the provider, Adapter fails closed before calling
WordPress Abilities REST. If no per-ability posture is declared, Adapter records
`implementation_posture_evidence.status=not_declared` instead of inventing a
local truth source.

Adapter discovery, commit-preflight, final execution, and stored execution
records expose `execution_handoff_posture` with schema
`npcink_openclaw_adapter_execution_handoff_posture.v1`. This is a visibility
contract for clients: Adapter is the channel and post-Core execution owner, Core
remains the approval, commit-preflight, and execution-record truth owner, and the
final local write surface is WordPress Abilities REST. The posture must keep
`core_proxy_execute=false`, `commit_execution=false`,
`generic_write_executor=false`, `workflow_runtime=false`, and
`queue_or_scheduler=false`. Final execute responses and stored execution records
also expose bounded `implementation_posture_evidence` so clients can see whether
provider posture was checked or absent for the executed ability ids.

## Unified Approve And Execute Contract

Adapter exposes one user-facing action for the minimal destructive execution
loop:

```text
POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/approve-and-execute
```

For a pending `npcink-abilities-toolkit/trash-post`, `npcink-abilities-toolkit/create-draft`,
`npcink-abilities-toolkit/update-post`, `npcink-abilities-toolkit/set-post-seo-meta`,
`npcink-abilities-toolkit/set-post-slug`, `npcink-abilities-toolkit/set-post-terms`,
`npcink-abilities-toolkit/delete-term`, `npcink-abilities-toolkit/update-media-details`,
`npcink-abilities-toolkit/patch-post-content`,
`npcink-abilities-toolkit/update-post-blocks`,
`npcink-abilities-toolkit/update-template-blocks`,
`npcink-abilities-toolkit/upsert-template-blocks`,
`npcink-abilities-toolkit/update-template-part-blocks`,
`npcink-abilities-toolkit/patch-setting-value`,
`npcink-abilities-toolkit/optimize-media-asset`,
`npcink-abilities-toolkit/replace-media-file`,
`npcink-abilities-toolkit/restore-media-backup`,
`npcink-abilities-toolkit/adopt-cloud-media-derivative`,
`npcink-abilities-toolkit/rename-media-file`,
`npcink-abilities-toolkit/delete-media-permanently`,
`npcink-abilities-toolkit/reply-comment`, `npcink-abilities-toolkit/trash-comment`, or
`npcink-abilities-toolkit/approve-comment` proposal, Adapter
fetches the proposal from Core,
calls Core approve, calls Core commit-preflight, verifies Core's approval
context and executable preflight result, then executes one WordPress Abilities
API call. For an already approved proposal, Adapter skips only the Core approve
step and still obtains commit-preflight authorization before execution.

The execution input may be either top-level `proposal.input` for an supported
ability or a bounded `proposal.input.write_actions[]` batch. `trash-post`
requires `post_id`; `create-draft` requires `title`; `update-post` requires
`post_id` plus at least one of `title`, `content`, or `excerpt`;
`set-post-seo-meta` requires `post_id` plus `seo_title` or
`seo_description`; `set-post-slug` requires `post_id` and a valid `slug`;
`set-post-terms` requires `post_id`, a valid `taxonomy`, `mode`, and
`term_ids` or `terms`, and does not create missing terms; `delete-term`
requires a valid `taxonomy` and `term_id`; `update-media-details` requires
`attachment_id` plus at least one media detail field; media `source_type`, when
provided, must be one of `owned`, `ai_generated`, `stock`, `external`, or
`test`; `upload-media-from-url` may accept a reviewed `file_name` for the new
media object; `patch-post-content` requires `post_id` and bounded exact replacement
	`operations`; `update-post-blocks` requires `post_id`, `blocks`, and optional
	`mode=replace|append`; `update-template-blocks` and
	`update-template-part-blocks` require `post_id`, `blocks`, and
	`mode=replace`; `upsert-template-blocks` requires `slug`, `blocks`, and
	`mode=replace`, with optional `post_id` for an existing template override;
	`patch-setting-value` requires `target_type`, `target_name`, and
bounded exact replacement `operations`. It is conditionally executable: the
host must allowlist the concrete target with
`npcink_abilities_toolkit_patchable_setting_targets`, and Adapter fails closed
with `npcink_openclaw_adapter_setting_target_not_ready` before approval or
commit-preflight when that site policy is absent. Adapter never exposes the
allowlisted target names and Toolkit retains its independent sensitive-target
block. `optimize-media-asset` requires `attachment_id`, may accept bounded
format, width, quality, and suffix inputs, and must preserve the original file;
`replace-media-file` requires `attachment_id`, uses a recorded
`derivative_relative_file`, and records backup metadata for rollback;
`restore-media-backup` requires `attachment_id` and `backup_id`, restores a
recorded backup after Core approval, and records rollback verification;
`adopt-cloud-media-derivative` requires `attachment_id` and
`derivative_artifact` evidence, may accept a reviewed `file_name` for the
adopted derivative, may carry reviewed inline media reference repair post/count
expectations, then delegates any approved local download, backup, attachment
pointer, metadata writes, and inline reference repair to the WordPress ability;
`rename-media-file` requires an existing attachment `attachment_id` and a
reviewed `target_file_name`, may accept expected current relative path, MIME
type, MD5, SHA256, conflict mode, and backup suffix guards, then delegates the
approved main-file rename and attachment URL update to the WordPress ability;
`delete-media-permanently` requires an existing attachment `attachment_id`;
`reply-comment` requires `comment_id`, non-empty `content`, and a valid
`content_format`; `trash-comment` requires `comment_id`;
`approve-comment` requires `comment_id`. See
[`openclaw-batch-execution-policy.md`](openclaw-batch-execution-policy.md).

The response must include `proposal_id`, `post_id`, `ability_id`,
`correlation_id`, `status_before`, whether Adapter performed approval, Core
`commit_execution=false`, an `execution_record` with
`core_execution_record`, and the execution result.
Rejected proposals, non-supported abilities, preflight failures, and
duplicate execution attempts must not execute.

## Approved Proposal Execution Contract

Adapter may execute one approved Core proposal only through:

```text
POST /wp-json/npcink-openclaw-adapter/v1/execute-approved-proposal
POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/execute
POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/approve-and-execute
```

The current supported profiles is intentionally narrow:

- `npcink-abilities-toolkit/trash-post`
- `npcink-abilities-toolkit/create-draft`
- `npcink-abilities-toolkit/update-post`
- `npcink-abilities-toolkit/set-post-seo-meta`
- `npcink-abilities-toolkit/set-post-slug`
- `npcink-abilities-toolkit/set-post-terms`
- `npcink-abilities-toolkit/delete-term`
- `npcink-abilities-toolkit/update-media-details`
- `npcink-abilities-toolkit/patch-post-content`
- `npcink-abilities-toolkit/update-post-blocks`
- `npcink-abilities-toolkit/update-template-blocks`
- `npcink-abilities-toolkit/upsert-template-blocks`
- `npcink-abilities-toolkit/update-template-part-blocks`
- `npcink-abilities-toolkit/patch-setting-value`
- `npcink-abilities-toolkit/optimize-media-asset`
- `npcink-abilities-toolkit/replace-media-file`
- `npcink-abilities-toolkit/restore-media-backup`
- `npcink-abilities-toolkit/adopt-cloud-media-derivative`
- `npcink-abilities-toolkit/rename-media-file`
- `npcink-abilities-toolkit/delete-media-permanently`
- `npcink-abilities-toolkit/reply-comment`
- `npcink-abilities-toolkit/trash-comment`
- `npcink-abilities-toolkit/approve-comment`

The supported profiles applies to both single-ability execution and each
`write_actions[]` item. A batch containing any non-supported action fails
closed and executes no actions.

The supported profiles is derived from Adapter's local execution profile registry, not
from capability discovery alone. Each profile entry is an explicit opt-in for
final WordPress writes and must define the Adapter-owned execution shape:

- ability id;
- required input checks such as `post_id`, `comment_id`, or `title`;
- ability-specific guards such as term taxonomy/mode validation;
- whether execution input must be rebuilt before dispatch;
- whether `post_id` should be read back from the ability result;
- smoke coverage for both the success path and Adapter-owned rejection paths.

OpenClaw may use capability discovery to decide what can be proposed, but
Adapter must use execution profiles to decide what can be executed after Core
approval and commit-preflight.

For abilities that have an Adapter execution profile, `POST /proposals` must
validate the profile-owned input shape before forwarding to Core. That includes
rejecting undeclared input fields and invalid enum values, so a proposal that
Adapter would later execute cannot be created with obviously invalid execution
input. For example, `npcink-abilities-toolkit/update-post` does not accept `status`, and
`npcink-abilities-toolkit/create-draft` only accepts `status=draft`.

For each execution request, Adapter must first reject any proposal with a
completed Adapter execution record. If there is no completed record, Adapter
must fetch the Core proposal, consume a cached Adapter preflight handoff when
one was issued through Adapter, otherwise call Core commit-preflight, require
`approval_commit_authorized=true`, require `commit_execution=false`, pass Core
`approval_context` to WordPress Abilities API, and return `proposal_id`,
`correlation_id`, `ability_id`, and `execution_record` with the ability result.
After a successful execution, Adapter stores only a bounded public-safe
execution record keyed by proposal id for replay protection and records the
execution outcome back to Core through
`/npcink-governance-core/v1/proposals/{proposal_id}/record-execution`. The
record may include a compact `verification` summary extracted from supported
Ability verification fields such as current media file, MIME type, post
reference verification, backup availability, and rollback availability; it
must not store the full proposal or full Ability response. When execution
fails after Core preflight has been consumed, Adapter stores the same bounded
public-safe record shape with `status=failed`, `error_code`, failed action
metadata, executed counts, and Core correlation only, and records
`execution_failed` in Core; it does not store the full proposal or create a
retry queue. Core remains the proposal, approval, preflight, execution-outcome,
and audit truth source.

For approved block writes, Adapter also performs a bounded post-execution
readback before storing the execution record. `update-post-blocks` is verified
through `npcink-abilities-toolkit/get-post-blocks`; `update-template-blocks`
and `upsert-template-blocks` are verified through
`npcink-abilities-toolkit/get-template-blocks`; `update-template-part-blocks`
is verified through `npcink-abilities-toolkit/get-template-part-blocks`. The
record keeps only compact counts, validation flags, and readback status. A
readback failure is recorded as verification metadata and does not turn an
already successful approved write into a retry queue or a second write path.

Adapter proposal detail must preserve Core's raw `status` and expose
Adapter-derived `effective_status` beside it. For example, a completed
successful Adapter execution should record `status=executed` in Core and
reports `effective_status=executed`, `execution_status=succeeded`,
`executable=false`, and `non_executable_reason=already_executed`.

Within one approved `write_actions[]` batch, Adapter may resolve exact output
references in action input values:

```text
$outputs.<prior_action_id>.<field>
```

References are evaluated only in memory while executing that batch, must point
to an earlier action in the same proposal, and must occupy the whole input
value. Output references cannot be embedded into larger strings. Adapter does
not persist run state, evaluate expressions, branch, loop, or resolve
references across proposals.

Adapter must not generate its own approval state, skip Core commit-preflight,
skip `approval_context`, execute unapproved proposals outside the unified
action, execute preflight failures, or batch silently execute destructive
actions. Future execution abilities must be added as explicit Adapter execution
profile entries with dedicated smoke coverage; Adapter must not become a
generic proxy-execute surface.

## AI Request Log Correlation

Adapter keeps Core audit and AI Request Logs separate, but it carries stable
correlation fields between them.

For read routes and future execution handoff routes, OpenClaw may pass:

- `proposal_id`;
- `correlation_id`;
- `external_thread_id`;
- `openclaw_thread_id`;
- `adapter_request_id`;
- `adapter_route`;
- a top-level `log_context` object on POST `/run-read-ability`.

Those six values are the complete client-writable context allowlist. Adapter
must not forward those reserved query fields as ability input. It derives
`ability_id`, `governance_source`, `via`, nested Core correlation fields, and
trusted caller provenance; caller input cannot override
`caller_type=openclaw_adapter`, `via=npcink-ai-client-adapter`, the current
ability id, governance source, or authenticated signed-client fingerprint.
Client log context is capped at 32 fields, two nested array levels, 200 bytes
per string, and approximately 8 KiB serialized, and secret-bearing keys are
removed. While an
ability is running, Adapter adds the sanitized values to AI Request Logs via the
`wpai_request_log_context` filter. The AI log context receives a
`npcink_openclaw_adapter` object, top-level provider correlation fields, and nested
`npcink_governance_core.proposal_id` / `npcink_governance_core.correlation_id` when present.

When a downstream provider integration emits an AI Request Logs row under
Adapter/Core correlation context, that row should include at least:

```text
proposal_id
correlation_id
ability_id
adapter_request_id
adapter_route
ai_provider
ai_model
governance_source=npcink-governance-core
```

Core Governance Audit is the governance log. WordPress `ai` plugin AI Request
Logs are the provider request log. Adapter carries identifiers between them but
does not store provider credentials, prompts, responses, token details, or AI
Request Logs in Core. Adapter does not expose a provider smoke route and does
not own model routing, prompt execution, provider credentials, or product UX.
Provider request log correlation should use bounded Adapter/Core request
context fields such as `adapter_request_id`, `proposal_id`, and
`correlation_id`.

## Proposal Status Read Proxy

OpenClaw connects to Npcink OpenClaw Adapter, not directly to Core. The adapter may
therefore expose these read-only Core proposal status routes:

```text
GET /wp-json/npcink-openclaw-adapter/v1/proposals
GET /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}
```

They relay to:

```text
GET /wp-json/npcink-governance-core/v1/proposals
GET /wp-json/npcink-governance-core/v1/proposals/{proposal_id}
```

The adapter should preserve Core proposal list/detail fields, including
`proposal_id`, `ability_id`, `status`, `title`, `summary`, `input`, `preview`,
`caller`, `created_at`, `updated_at`, and detail `audit_timeline` when Core
returns it. If a Core app token is used in a direct Core auth path or future
trusted handoff, that key must include `proposals:read`.

The Adapter admin page may expose a focused `Proposal status` lookup that uses
the same read-only proxy. That lookup can show Core status, link to the Core
approval detail, and copy Adapter status or approved-execution endpoints. It
must not become a Core approval table, Core audit table, or generic
approve/reject proxy.

The adapter must not print Core tokens in logs, proposal payloads, error
responses, or documentation examples. It must not add proposal approval or
rejection proxy routes by default.

Adapter Core app token configuration may come only from the
`NPCINK_OPENCLAW_ADAPTER_CORE_APP_TOKEN` constant or environment variable. It
is not read from a WordPress option. When configured, the token is used
only for internal Core REST calls through the request header supported by Core,
and the raw value must not appear in health, help, handoff text, error details,
proposal payloads, or docs examples.

The narrow Adapter governance token for proposal create, proposal status, and
commit-preflight needs only `proposals:create`, `proposals:read`, and
`commit:preflight`. It must not include `proposals:approve`, `proposals:reject`,
or `audit:read`. If a full Adapter discovery smoke also calls Core
capabilities, add `capabilities:read` for that wider smoke only.

## Persistence Boundary

Adapter may keep bounded local options for device pairing, local client public
keys, preflight handoffs, execution idempotency records, and short-lived locks.
These options are local bridge state only. They are not Core proposal truth,
approval truth, audit truth, or a durable execution-history database.

The execution record option is capped and time-bounded. It exists so repeated
commit requests for the same Core-approved proposal can fail closed or return a
recent execution summary. The Core proposal lifecycle and Core audit log remain
the durable source of truth after Adapter records an execution outcome back to
Core.

Adapter must not create custom WordPress tables for workflow runs, queues,
approval records, audit records, provider request logs, or Cloud runtime
history. If a future feature needs long-term queryable history, retry queues,
dead-letter handling, or support diagnostics, route that state to Core or Cloud
through a new boundary decision instead of adding Adapter-owned tables.

## Approval Disabled Stub Contract

The adapter exposes these routes only as generic approval proxy routes:

```text
POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/approve
POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/reject
```

Default response:

```json
{
  "code": "npcink_openclaw_adapter_execute_profile_unsupported",
  "message": "Use POST /proposals/{proposal_id}/approve-and-execute for the Adapter unified user action, or use Npcink Governance Core admin for split approval decisions.",
  "approval_proxy_enabled": false,
  "approval_surface": "npcink_governance_core_admin",
  "unified_action_route": "POST /proposals/{proposal_id}/approve-and-execute"
}
```

The generic approval proxy routes must not forward to Core approval or rejection routes. The
default Core app key used by Adapter must not require approval or rejection
scopes. OpenClaw and agents must not receive default approval power through
standalone approve/reject proxy routes.

The supported Adapter-side approval action is the unified
`approve-and-execute` route. Adapter must not expose a generic approve/reject
proxy without a separate explicit trusted-host policy and ADR-backed feature.
The generic approval proxy routes and top-level health contract preserve
`approval_surface=npcink_governance_core_admin` to make the standalone proxy boundary
explicit.

## First Product Routes

Connection:

- WordPress admin: `Npcink -> Adapter`
- `GET /wp-json/npcink-openclaw-adapter/v1/health`
- `GET /wp-json/npcink-openclaw-adapter/v1/help`
- `GET /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/media-optimization-readiness`

Direct reads must use the generic route:

- `POST /wp-json/npcink-openclaw-adapter/v1/run-read-ability`

Adapter does not expose direct-read shortcut routes or workflow recipe shortcut
routes. The caller supplies the allowlisted `ability_id` and bounded input.

Diagnostics shortcuts must remain aliases over `npcink-abilities-toolkit`
direct-read abilities. Adapter must not collect plugin details, error-log
details, current-user capabilities, PHP extension state, database details,
rewrite state, cron details, roles, widgets, block-theme details, or search
status itself.

`wp-diagnostics-summary` is only a quick overview. OpenClaw must not use it to
decide whether plugin details, current-user permission details, or error-log
details are missing. All P0/P1/P2 troubleshooting detail shortcuts call
`npcink-abilities-toolkit/wp-ops-diagnostics-detail`.

Default detail input:

```json
{
  "include_log_contents": false,
  "include_active_plugins": true,
  "include_inactive_plugins": false,
  "include_plugin_updates": true,
  "include_must_use_plugins": true,
  "include_dropins": true,
  "max_plugins_per_group": 100
}
```

Deep plugin conflict input:

```json
{
  "include_log_contents": false,
  "include_active_plugins": true,
  "include_inactive_plugins": true,
  "include_plugin_updates": true,
  "include_must_use_plugins": true,
  "include_dropins": true,
  "max_plugins_per_group": 200
}
```

Explicit log inspection input:

```json
{
  "include_log_contents": true,
  "tail_lines": 50,
  "severity": ["fatal", "error", "warning"],
  "since_minutes": 1440
}
```

When `include_log_contents=false`, log contents are not missing; mark them as
not explicitly requested. OpenClaw should use `error_log.summary` for
`fatal_count`, `error_count`, `warning_count`, `deprecated_count`,
`notice_count`, `summary_source`, and `error_log.summary.by_severity` without
forcing log contents. Only display `error_log.tail_entries` or `contents` after
explicit log inspection. Inactive plugin rows are not requested by default and
must not be marked missing; use the deep plugin conflict input when the user
needs inactive plugin rows. Adapter must not implement `include_log_tail`
compatibility. Adapter also must not mix Npcink runtime, MCP, or cloud status
into this WordPress diagnostics mapping.

The diagnostics detail response is expected to preserve these fields when the
ability returns them:

- P0: `plugins.groups_included`, `plugins.max_plugins_per_group`,
  `plugins.available_count`, `plugins.active_count`,
  `plugins.inactive_count`, `plugins.update_available_count`,
  `plugins.mu_count`, `plugins.dropin_count`, `plugins.active`,
  `plugins.inactive`, `plugins.update_available`, `plugins.must_use`,
  `plugins.dropins`, `current_user`, `error_log`
- P1: `php.extensions.loaded`, `php.extensions.common_status`,
  `object_cache`, `rewrite`, `database`, `server`
- P2: `https`, `content_types`, `roles`, `widgets`, `block_theme`, `search`,
  `integrations`, `seo_summary`, `security_summary`, `performance_summary`,
  `cron_events.events`

Plugin rows should be displayed with `slug`, `plugin_file`, `name`, `version`,
`author`, `status`, `network_active`, `must_use`, `requires_wp`,
`requires_php`, `dependencies`, `dependency_count`, `is_npcink`,
`update_available`, and `latest_version`. Current-user rows should display
`user_id`, `user_login`, `display_name`, `roles`, `capabilities`,
`common_capabilities`, and `npcink_permissions`. Error-log rows should
display `contents_included`, `log_exists`, `log_readable`, `log_size_bytes`,
`log_modified_gmt`, `summary`, `summary.returned_lines`,
`summary.fatal_count`, `summary.error_count`, `summary.warning_count`,
`summary.deprecated_count`, `summary.notice_count`, `summary.info_count`,
`summary.unknown_count`, `summary.latest_fatal_at`, `summary.latest_error_at`,
`summary.latest_warning_at`, `summary.latest_deprecated_at`,
`summary.latest_notice_at`, `summary.summary_source`,
`summary.by_severity`, `severity_filter`, and `since_minutes`. Display
`tail_entries` and `contents` only when `contents_included=true`.

Content shortcuts forward query parameters into the ability input, including
`npcink-abilities-toolkit/list-posts` filters (`author_id`, `taxonomy`, `term_id`,
`term_slug`, `date_after`, `date_before`, `modified_after`,
`modified_before`, `orderby`, `order`), term sample-post flags
(`include_sample_posts`, `sample_post_limit`), user `author_profile`, comment
post context, media `attached_to`/`usage`, and `npcink-abilities-toolkit/get-menu` tree output.
Adapter does not reshape those fields.

Governance:

- `GET /wp-json/npcink-openclaw-adapter/v1/capabilities`
- `GET /wp-json/npcink-openclaw-adapter/v1/proposals`
- `GET /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}`
- `POST /wp-json/npcink-openclaw-adapter/v1/proposals`
- `POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan`
- `POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/approve`
- `POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/reject`
- `POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/commit-preflight`
- `POST /wp-json/npcink-openclaw-adapter/v1/execute-approved-proposal`
- `POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/execute`
- `POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/approve-and-execute`

## Security

All routes require `manage_options` through WordPress REST authentication.
OpenClaw should connect with a dedicated administrator Application Password for
the local PoC. Narrower adapter identity and scope can be added after the first
product flow is proven.

The Npcink OpenClaw Adapter admin page may display endpoint URLs, health state,
example requests, a non-secret connection manifest, and a handoff prompt. It
may create a normal WordPress Application Password for the current
administrator and show the raw password once in the browser. It must not store
raw secrets in adapter options, manifest JSON, handoff text,
example curl commands, files, logs, proposal payloads, create Core app keys,
persist connection state, approve proposals, or change Core/Abilities ownership.

## Application Password Handoff

Handoff data:

- `connection_id`, such as `local-wordpress`.
- Adapter base URL.
- WordPress username for the dedicated OpenClaw account.
- Auth type `wordpress_application_password`.
- Application Password UUID.
- Health, help, and capabilities URLs.
- A note that the Application Password must be stored through OpenClaw's
  dedicated secret field or credential vault, not chat, tools, files, logs,
  proposal payloads, or copied handoff text.

For the current LocalWP development site only, the WordPress administrator
browser login is username `1` and password `1`. This local-only password is for
admin browser access and Application Password creation; OpenClaw REST
configuration should use a dedicated Application Password.

If OpenClaw does not expose a credential store or import endpoint, paste the
Application Password only into OpenClaw's dedicated secret field. Do not paste
it into chat.

Local credential brokers should use key-pair device pairing instead of browser
secret handling:

```text
GET  /wp-json/npcink-openclaw-adapter/v1/connection/manifest
POST /wp-json/npcink-openclaw-adapter/v1/connect/device/start
POST /wp-json/npcink-openclaw-adapter/v1/connect/device/poll
GET  /wp-json/npcink-openclaw-adapter/v1/connection/key-pairs
```

`/connect/device/start` accepts public client metadata and an Ed25519 public
key. The WordPress admin approval page binds that public key to the approving
administrator. `/connect/device/poll` returns connection metadata after
approval. It never returns a WordPress Application Password or private key.

Signed Adapter requests use the `Npcink-OpenClaw-Adapter-V1` canonical request and
`X-Npcink-*` signing headers documented in
`docs/keypair-device-pairing-contract.md`.

OpenClaw must use WordPress REST Basic Auth:

```text
Authorization: Basic base64(username:<openclaw-secret-field-value>)
```

Connection check order:

1. `GET /health`.
2. `GET /help`.
3. `GET /capabilities`.
4. direct-read ability execution with `POST /run-read-ability`.
5. optional plan handoff with `POST /proposals/from-plan`.
6. proposal-required `POST /proposals`.
7. proposal status polling with `GET /proposals/{proposal_id}`.
8. unified user action with `POST /proposals/{proposal_id}/approve-and-execute`
   for supported execution, or split approval in Core admin.
9. rejected proposal stops the flow.
10. approved proposal split path uses `POST /proposals/{proposal_id}/execute`;
    Adapter commit-preflight is diagnostic and must be followed immediately by
    Adapter execute.

## Proposal-Required Write Flow

OpenClaw must treat Core as the only proposal and approval truth:

1. Read `/capabilities` and select a real `ability_id` where
   `governance_mode=proposal_required`.
2. Send `POST /proposals` with the real `ability_id`, dry-run style `input`,
   rendered or structured `preview`, and `caller` metadata.
3. Poll `GET /proposals/{proposal_id}` through the adapter for Core status.
4. If `status=pending` and the user chooses the unified OpenClaw action, call
   `POST /proposals/{proposal_id}/approve-and-execute`. Adapter calls Core
   approve, then Core commit-preflight, then one supported final write.
5. If `status=rejected`, stop and show the rejection state or reason returned
   by Core.
6. If using the lower-level split path and `status=approved`, call
   `POST /proposals/{proposal_id}/execute`. Use Adapter commit-preflight only
   as an advanced diagnostic step. For dry-run-only verification, stop at
   commit-preflight and do not call execute. If execution is intended, Adapter
   execute normalizes ability input to `dry_run=false` and `commit=true`.
7. Adapter validates any Core capability `implementation_posture` for the
   target ability before dispatching WordPress Abilities REST. Invalid or
   boundary-expanding posture blocks execution; absent posture is recorded as
   `not_declared`.
8. Adapter stops unless the ability is
   supported for Adapter execution, currently `npcink-abilities-toolkit/trash-post`,
   `npcink-abilities-toolkit/create-draft`, `npcink-abilities-toolkit/update-post`,
   `npcink-abilities-toolkit/set-post-seo-meta`, `npcink-abilities-toolkit/set-post-slug`,
   `npcink-abilities-toolkit/set-post-terms`, `npcink-abilities-toolkit/delete-term`,
   `npcink-abilities-toolkit/update-media-details`, `npcink-abilities-toolkit/patch-post-content`,
   `npcink-abilities-toolkit/update-post-blocks`,
   `npcink-abilities-toolkit/patch-setting-value`,
   `npcink-abilities-toolkit/optimize-media-asset`,
   `npcink-abilities-toolkit/replace-media-file`, `npcink-abilities-toolkit/restore-media-backup`,
   `npcink-abilities-toolkit/adopt-cloud-media-derivative`,
   `npcink-abilities-toolkit/rename-media-file`,
   `npcink-abilities-toolkit/delete-media-permanently`,
   `npcink-abilities-toolkit/reply-comment`, `npcink-abilities-toolkit/trash-comment`, and
   `npcink-abilities-toolkit/approve-comment`.

Adapter invariants:

- It can call Core approve only inside
  `POST /proposals/{proposal_id}/approve-and-execute`.
- It does not store proposal or approval state.
- It stores bounded execution records only to prevent replaying an already
  completed Adapter write.
- It owns only explicit post-Core execution profile policy for supported
  approved writes.
- It does not expose a generic approve/reject proxy.
- It does not execute final WordPress mutations outside the supported
  approve-and-execute or approved-proposal execution path.
- It preserves `core_proxy_execute=false`.
- It preserves `commit_execution=false`.
- It exposes `core_proxy_execute=false`.
- It keeps Core as the approval, preflight, and audit truth source.

For read-only planning abilities, OpenClaw may instead send the returned plan
to `POST /proposals/from-plan`. Adapter only forwards the plan to Core after it
has applied Adapter-owned schema checks to profiled `plan.write_actions[]`
inputs. Invalid profiled action input returns
`npcink_openclaw_adapter_plan_action_input_invalid` with `blocked_items[]` and no
Core proposal creation. Exact `$outputs.<prior_action_id>.<field>` references
are accepted only when they point to an earlier action in the same plan, then
resolved and revalidated during approved batch execution. Embedded `$outputs.`
tokens and duplicate plan action ids fail closed before Core forwarding. Core
still owns plan intake, proposal creation, remaining blocked items, approval
state, and audit truth.

Future standalone approval or rejection proxying is out of this default
contract. It may only be added as a separate explicit trusted-host policy and
ADR-backed feature, disabled by default, with independent Core scopes for
approval and rejection.
