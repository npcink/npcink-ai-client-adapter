# Npcink AI Client Adapter

Npcink AI Client Adapter is a thin AI client channel plugin for WordPress.

It gives OpenClaw-compatible and similar AI clients one WordPress REST namespace that can:

- read Npcink Governance Core capability guidance;
- run approved direct-read abilities through WordPress Abilities API;
- create Core proposals for write or destructive operations;
- forward one user-triggered approve-and-execute request to Core.

AI clients should connect through Adapter. Npcink Governance
Core is the governance service behind Adapter. Core remains the approval,
preflight, and audit truth source; Adapter exposes the productized channel
actions and does not rely on controlling any specific external AI client.

It does not define abilities, store approval state, run workflows, expose a
generic approve/reject proxy, or execute final write mutations without Core
approval, commit-preflight, and an explicit Adapter execution profile.
Adapter does not make approval decisions or persist approval state; Core remains
the only approval, preflight, and audit truth.

When Core or Adapter blocks a plan handoff, rejected proposal, or preflighted
execution, error responses may include `data.operator_feedback`. Clients should
display that object to the operator and create a revised new proposal instead
of retrying execution against the blocked proposal id. Core remains the
governance truth.

AI Client Adapter consumer readiness is complete as of Adapter governance commit
`b81dc2a`. Productized clients should use Adapter as the only entry point for
WordPress/Core governance and WordPress Abilities API channel operations.
Adapter is not the entry point for Cloud runtime transport, provider execution,
workflow runtime, or client-side credential storage.
Cross-project platform coordination starts from
`/Users/muze/gitee/npcink-workflow-toolbox/docs/platform/README.md`; this
repository remains authoritative only for Adapter-owned channel contracts.
See [OpenClaw Adapter Consumer Readiness](docs/openclaw-adapter-consumer-readiness.md)
for the dependency snapshot, verified routes, closed loop, and next-stage
execution allowlist rules.
See [Adapter Contract Reuse Readiness - 2026-07-08](docs/adapter-contract-reuse-readiness-2026-07-08.md)
before adding new Adapter routes, execution profiles, or channel-owned runtime
behavior for the Core/Toolkit/Product reuse chain.
See [Adapter Onboarding Reference Notes - 2026-07](docs/adapter-onboarding-reference-notes-2026-07.md)
before turning webhook or automation plugin inspiration into Adapter
connection, payload, or failure-feedback changes.

Batch plan execution is intentionally narrow. Adapter can execute
`input.write_actions[]` only after Core approval and commit-preflight, and only
when every action targets the current execution allowlist
(`npcink-abilities-toolkit/trash-post`, `npcink-abilities-toolkit/create-draft`,
`npcink-abilities-toolkit/update-post`,
`npcink-abilities-toolkit/patch-post-content`,
`npcink-abilities-toolkit/update-post-blocks`,
`npcink-abilities-toolkit/update-template-blocks`,
`npcink-abilities-toolkit/upsert-template-blocks`,
`npcink-abilities-toolkit/update-template-part-blocks`,
`npcink-abilities-toolkit/patch-setting-value`,
`npcink-abilities-toolkit/set-post-seo-meta`,
`npcink-abilities-toolkit/adopt-article-audio`,
`npcink-abilities-toolkit/set-post-slug`,
`npcink-abilities-toolkit/set-post-terms`,
`npcink-abilities-toolkit/delete-term`,
`npcink-abilities-toolkit/update-media-details`,
`npcink-abilities-toolkit/upload-media-from-url`,
`npcink-abilities-toolkit/set-post-featured-image`,
`npcink-abilities-toolkit/optimize-media-asset`,
`npcink-abilities-toolkit/replace-media-file`,
`npcink-abilities-toolkit/restore-media-backup`,
`npcink-abilities-toolkit/adopt-cloud-media-derivative`,
`npcink-abilities-toolkit/rename-media-file`,
`npcink-abilities-toolkit/delete-media-permanently`,
`npcink-abilities-toolkit/reply-comment`, `npcink-abilities-toolkit/trash-comment`,
`npcink-abilities-toolkit/approve-comment`). See
[OpenClaw Batch Execution Policy](docs/openclaw-batch-execution-policy.md).
These abilities remain owned by `npcink-abilities-toolkit`; Adapter only owns the
post-Core execution profile policy that calls approved abilities after Core
approval and commit-preflight.
Batch execution responses expose selected/submitted/executed/failed counts,
per-action status, execution profile, idempotency key, Core preflight evidence,
retryability, and `operator_next_action` so product surfaces such as Toolbox can
render approved batch outcomes without owning a queue or write executor.

## Runtime Boundary

Layer ownership:

| Layer | Plugin | Responsibility |
| --- | --- | --- |
| Ability layer | `npcink-abilities-toolkit` | Registers canonical abilities, schemas, callbacks, permissions, and dry-run previews. |
| Governance layer | `npcink-governance-core` | Discovers abilities, classifies risk, stores proposals, handles approval/preflight, and audits governance decisions. |
| Workflow layer | `npcink-workflow-toolbox` | Owns operator workflow surfaces and registers Toolbox planning abilities. |
| Channel layer | `npcink-ai-client-adapter` | Gives AI clients a small REST adapter that calls Core and WordPress Abilities API. |

`npcink-workflow-toolbox` is the current plugin slug and repository path for the
workflow surface. Its registered WordPress ability ids currently retain the
`npcink-toolbox/*` namespace for compatibility; Adapter treats those ids as
external abilities and does not own their callbacks or workflow runtime.

## Suite Distribution

Npcink AI Client Adapter is the productized entry plugin for the Npcink AI suite,
but distribution does not merge runtime ownership. The suite package ships
Adapter, Core, and the Abilities Toolkit as separate WordPress plugin zips and
keeps their REST namespaces, plugin headers, data stores, and tests separate.

The current Adapter plugin header declares `Requires Plugins:
npcink-abilities-toolkit, npcink-governance-core`. Runtime readiness is still
detected through REST routes and public functions, so dependency metadata does
not make Adapter the owner of Core proposal, approval, preflight, or audit
truth. Adapter's own slug remains a distribution contract value for suite
packaging.

Adapter `/health` and `/help` remain available when dependencies are missing.
Routes that require Core or WordPress Abilities API fail closed with
`npcink_openclaw_adapter_missing_dependency`. See
[Npcink AI Suite Distribution Contract](docs/distribution-contract.md).

When Core and the Abilities Toolkit expose their admin-only runtime contract
endpoints, Adapter also includes a bounded `dependency_contracts` summary on
`/health`, `/help`, and `/connection/manifest`. The summary verifies the Core
`npcink_governance_core_contract.v1` and Toolkit
`npcink_abilities_toolkit_contract.v1` schema versions against Adapter's
declared minimum floors, then carries only compatibility and boundary fields,
such as Core's `provider_secret_storage=false` posture and Toolkit's
`host_governed_writes=true` write control. It does not copy Core proposal,
approval, or audit state, and it does not expose secrets.

## REST Surface

All routes require `manage_options` through normal WordPress REST
authentication, such as an administrator Application Password.

- `GET /wp-json/npcink-openclaw-adapter/v1/health`
- `GET /wp-json/npcink-openclaw-adapter/v1/help`
- `GET /wp-json/npcink-openclaw-adapter/v1/capabilities`
- `GET /wp-json/npcink-openclaw-adapter/v1/connection/manifest`
- `POST /wp-json/npcink-openclaw-adapter/v1/connect/device/start`
- `POST /wp-json/npcink-openclaw-adapter/v1/connect/device/poll`
- `GET /wp-json/npcink-openclaw-adapter/v1/connection/key-pairs`
- `DELETE /wp-json/npcink-openclaw-adapter/v1/connection/key-pairs/{key_id}`
- `POST /wp-json/npcink-openclaw-adapter/v1/run-read-ability`
- `GET /wp-json/npcink-openclaw-adapter/v1/read-requests`
- `POST /wp-json/npcink-openclaw-adapter/v1/read-requests`
- `GET /wp-json/npcink-openclaw-adapter/v1/read-requests/{request_id}`
- `GET /wp-json/npcink-openclaw-adapter/v1/proposals`
- `GET /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}`
- `POST /wp-json/npcink-openclaw-adapter/v1/proposals`
- `POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan`
- `GET /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/media-optimization-readiness`
- `POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/commit-preflight`
- `POST /wp-json/npcink-openclaw-adapter/v1/execute-approved-proposal`
- `POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/execute`
- `POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/approve-and-execute`

Adapter does not expose direct-read shortcut routes, workflow recipe routes,
provider/model smoke routes, or Cloud/media derivative façade routes. Use
`POST /run-read-ability` for approved reads, Core proposal routes for governed
writes, and `npcink-cloud-addon` for Cloud runtime transport.

Disabled compatibility stubs:

- `POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/approve`
- `POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/reject`

These return HTTP 403 with
`code=npcink_openclaw_adapter_approval_proxy_disabled`,
`approval_proxy_enabled=false`, and
`approval_surface=npcink_governance_core_admin`. Adapter does not forward
standalone approve/reject decisions to Core.

Diagnostic reads should call `POST /run-read-ability` with the underlying
Toolkit ability id and input. Adapter does not collect these facts itself.
`npcink-abilities-toolkit/wp-diagnostics-summary` is only a quick overview.
P0/P1/P2 troubleshooting detail reads should call
`npcink-abilities-toolkit/wp-ops-diagnostics-detail`.

Default diagnostics detail input is:

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

For deep plugin conflict troubleshooting, call
`POST /run-read-ability` with `ability_id` set to
`npcink-abilities-toolkit/wp-ops-diagnostics-detail` and input such as:

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

When the operator explicitly asks to inspect logs, use `recent-error-log-tail` or
send the equivalent input:

```json
{
  "include_log_contents": true,
  "tail_lines": 50,
  "severity": ["fatal", "error", "warning"],
  "since_minutes": 1440
}
```

When `include_log_contents=false`, log contents are not missing; mark them as
not explicitly requested. Clients should use `error_log.summary` for
`fatal_count`, `error_count`, `warning_count`, `deprecated_count`,
`notice_count`, `summary_source`, and `error_log.summary.by_severity` even when
contents are not included. Only display `error_log.tail_entries` or `contents`
after the user explicitly asks for logs. Inactive plugin rows are not requested
by default; show them as default not requested, not missing. Plugin details are
grouped by `plugins.active`, `plugins.inactive`, `plugins.update_available`,
`plugins.must_use`, and `plugins.dropins`, with `plugins.groups_included` and
`plugins.max_plugins_per_group` describing which groups were requested. The
compatibility `contents` array is only redline text for display. The
diagnostics abilities own redaction and schema boundaries. Adapter does not
read arbitrary files, expose database names/table names, collect secrets,
invent diagnostics data, or mix Npcink runtime, MCP, or cloud state into the
WordPress diagnostics mapping.

Content reads should call `POST /run-read-ability` with the underlying ability
input, including the current `npcink-abilities-toolkit/list-posts` filters,
richer `npcink-abilities-toolkit/get-post-context` output, term sample-post
flags, user `author_profile`, comment post context, media
`attached_to`/`usage`, and `npcink-abilities-toolkit/get-menu` tree output.

For media metadata optimization, call `POST /run-read-ability` with the
read-only `npcink-abilities-toolkit/optimize-media-metadata` ability. Adapter
does not own a dedicated metadata shortcut route. To apply reviewed
suggestions, create a governed Core proposal for
`npcink-abilities-toolkit/update-media-details`, then execute through Adapter's
existing Core-approved allowlisted path.

For media format attention, `POST /run-read-ability` may call
`npcink-abilities-toolkit/inspect-media-asset`,
`npcink-abilities-toolkit/resolve-media-attachment-by-url`, or the relevant
Toolkit planning ability directly. Adapter does not own shortcut aliases for
these reads.

For Cloud-generated media derivatives, Cloud transport and run/result truth
belong to `npcink-cloud-addon`. Adapter may check proposal-specific readiness
and may execute the explicit
`npcink-abilities-toolkit/adopt-cloud-media-derivative` profile after Core
approval and commit preflight, but it does not expose
`/media-derivative-runs`, artifact preview, or derivative proposal-payload
routes. Build Cloud derivative payloads in Cloud Addon or Toolkit/Core plan
abilities such as `npcink-abilities-toolkit/build-media-optimization-plan` and
`npcink-abilities-toolkit/build-media-adoption-preflight-summary`, then submit
reviewed write actions through `POST /proposals/from-plan`.

Adapter does not create a media registry, artifact registry, Cloud settings
surface, approval truth, attachment metadata update, or file replacement. To
adopt a Cloud derivative artifact as the attachment main file, create a
governed Core proposal for `npcink-abilities-toolkit/adopt-cloud-media-derivative`, then
execute only through Adapter's Core-approved allowlisted path. To switch the
attachment main file to an already recorded local derivative, create a governed
Core proposal for `npcink-abilities-toolkit/replace-media-file`; both write abilities record
backup metadata for later restore. To roll back a recorded media replacement,
create a governed Core proposal for `npcink-abilities-toolkit/restore-media-backup`
with `attachment_id` and `backup_id`. Adapter does not accept arbitrary
replacement URLs or replace files outside Core-approved execution.
Adapter may forward Toolkit storage preflight and drift guard fields such as
`expected_storage_provider`, `expected_storage_adapter`, and
`storage_preflight` to those Core-approved media execution profiles. These
fields are evidence for fail-closed execution; they do not make Adapter an OSS
configuration, upload, cache-purge, or media-storage owner.

`GET /proposals/{proposal_id}` proxies the Core proposal detail and appends
Adapter-owned derived fields: `adapter_status`, `execution_status`,
`effective_status`, `executable`, `non_executable_reason`, `preflight_status`,
`review_summary`, and, for media optimization batches,
`media_optimization_readiness`. Adapter also forwards Core batch review
summaries as `batch_review_feedback` on `/proposals/from-plan`,
`/proposals/{proposal_id}/commit-preflight`, and final execute responses when
Core provides `preview.batch_review_summary`. This gives clients
`operator_next_action`, blocked counts, target ability ids, and retryability
without creating an Adapter queue, scheduler, approval store, or unattended
execution runtime. `status` remains the raw Core governance
status, while `effective_status` folds Adapter execution evidence into values
such as `executed` without changing Core's approval truth. The same media
readiness object is available through
`GET /proposals/{proposal_id}/media-optimization-readiness`. The readiness
checks are diagnostic only and do not download Cloud artifacts; they report
local helper availability, artifact presence/expiry, Adapter execution-profile
validation, and whether post-content reference scan evidence is present. For
older proposal previews, Adapter derives actual replacement counts from
`patch_preview[].applied` when the newer
`actual_replacement_count`/`unmatched_rules` fields are absent.

Reserved governance correlation query parameters are not forwarded as ability
input. Adapter copies `proposal_id`, `correlation_id`, `external_thread_id`,
`openclaw_thread_id`, `ability_id`, `adapter_request_id`, `adapter_route`,
`ai_provider`, `ai_model`, `governance_source=npcink-governance-core`, and nested
`npcink_governance_core.proposal_id` / `npcink_governance_core.correlation_id` into AI Request
Logs context through the `wpai_request_log_context` filter while an ability or
downstream provider integration request is running. POST `/run-read-ability` accepts
these values in a top-level `log_context` object. This lets AI Request Logs
execution rows correlate with Core proposal and commit-preflight audit records
without merging the two log systems.
Adapter performs identifier passthrough only; Core Audit and AI Request Logs
remain separate truth sources.

Core Governance Audit is the governance log. WordPress `ai` plugin AI Request
Logs are the provider request log. Adapter carries identifiers between them but
does not put provider credentials, prompts, responses, token details, or AI
Request Logs into Core.
AI Request Logs are the provider request log.

## Npcink AI Client Adapter UI

WordPress administrators can open:

```text
Npcink -> Adapter
```

The page default view shows:

- a simple Application Password connection for clients with a dedicated
  password, credential, or secret field;
- Adapter base URL and non-secret connection manifest URL;
- Core and WordPress Abilities API connection status;
- a higher-security signed key-pair flow using `cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.2.0 -- npcink-openclaw-adapter`;
- authorized public key management with revoke actions;
- the minimal information needed to continue in client tooling or Core admin
  without turning Adapter into a proposal queue.

Advanced disclosures keep lower-frequency reference details available without
turning the page into a control panel:

- supported read ability ids and example `POST /run-read-ability` payloads;
- flat `GET /help` route rows under `routes`, plus human-readable
  `route_groups`;
- Application Password secret-field steps;
- a non-secret connection manifest with `connection_id`, adapter URLs,
  username, auth type, and `password_uuid`;
- a copyable WorkBuddy setup block that reuses the same non-secret manifest and
  tells WorkBuddy where the secret must be stored;
- key-pair device pairing MVP endpoints and local CLI reference details;
- copyable health and proposal example requests;
- proposal list/detail, plan-to-proposal, commit-preflight, and
  approve-and-execute routes;
- read-only proposal status examples for developer integration;
- a copyable local AI client session opener.

The page does not save adapter credentials, approval state, ability definitions,
workflow state, or final write policy. The handoff action creates a normal
WordPress Application Password and displays the raw value once; WordPress stores
only its hash. Copied env, manifest, and handoff text contain only placeholders
or non-secret identifiers. Paste the Application Password only into OpenClaw's
dedicated secret field, not chat, tool commands, logs, proposal payloads, files,
or copied handoff text. The Adapter cannot control a customer-selected AI
client; enforceable boundaries live in Adapter routes, CLI redaction,
`client_policy`, WordPress REST authentication, and Core approval/preflight.

Public Key Device Pairing: for clients with a local broker, the Adapter REST surface supports a key-pair
device pairing MVP. The client generates an Ed25519 private key locally, sends
only the public key to WordPress for admin approval, and signs later Adapter
requests:

```text
GET  /wp-json/npcink-openclaw-adapter/v1/connection/manifest
POST /wp-json/npcink-openclaw-adapter/v1/connect/device/start
POST /wp-json/npcink-openclaw-adapter/v1/connect/device/poll
GET  /wp-json/npcink-openclaw-adapter/v1/connection/key-pairs
```

For local validation, use the npm CLI on the same machine or execution
environment as OpenClaw:

```bash
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.2.0 -- npcink-openclaw-adapter connect --site=https://npcink.local --profile=local --insecure-local-tls
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.2.0 -- npcink-openclaw-adapter status --profile=local --insecure-local-tls
```

The script opens the WordPress approval URL in the system browser. Approve the
public key, and the script will save a local profile under
`~/.npcink-openclaw-adapter/keypair-profiles/` before testing a signed `GET /health`
request. Use `--no-open` if you want to print the URL without opening a browser.
The profile contains the local private key; do not paste or log it. Production
clients should store the private key in the OS keychain or the client credential
vault. The `--insecure-local-tls` flag is for LocalWP or `.local` self-signed
HTTPS only; do not use it for a public or shared WordPress site. Transient local
HTTPS polling resets are retried until the pairing code expires.
After approval, WordPress shows a pairing result page; return to the terminal or
local AI client and wait for polling to finish.

The `status` command keeps the raw `/health` boundary fields and also prints
derived `boundary` and `proposal_execution` guidance. In a healthy Adapter
connection, `approval_proxy_enabled=false`, `core_proxy_execute=false`, and
`commit_execution=false` are expected boundary controls. They mean Core did not
execute final writes and standalone approval proxying is disabled; they are not
an execution-disabled signal. For proposal execution readiness, inspect
`GET /proposals/{proposal_id}` and use the Adapter approve-and-execute or
execute routes only after Core approval and commit-preflight.
In that post-Core phase, Adapter executes only explicit supported execution
profiles; Core still owns approval state and commit-preflight truth.
If Core approval or commit-preflight fails, Adapter returns the blocked Core
evidence/operator feedback and does not mutate Adapter-side approval state.

After pairing, local clients can call Adapter through the signed request command
without reading or printing profile secrets:

```bash
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.2.0 -- npcink-openclaw-adapter request --profile=local --insecure-local-tls GET /health
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.2.0 -- npcink-openclaw-adapter request --profile=local --insecure-local-tls GET /capabilities
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.2.0 -- npcink-openclaw-adapter request --profile=local --insecure-local-tls POST /proposals/from-plan --body-file=/tmp/npcink-proposal.json
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.2.0 -- npcink-openclaw-adapter request --profile=local --insecure-local-tls POST /proposals/PROPOSAL_ID/commit-preflight --intent=preflight
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.2.0 -- npcink-openclaw-adapter request --profile=local --insecure-local-tls POST /proposals/PROPOSAL_ID/approve-and-execute --intent=commit
```

For sensitive reads, prefer the narrower CLI helpers instead of asking an AI
client to hand-build JSON route bodies:

```bash
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.2.0 -- npcink-openclaw-adapter read-request create --profile=local --insecure-local-tls --ability-id=npcink-abilities-toolkit/wp-ops-diagnostics-detail --input-file=/tmp/read-input.json --purpose="Review bounded diagnostics" --data-classes=diagnostics,logs --redaction-level=strict --max-rows=10 --tail-lines=5 --denied-fields=authorization,cookie,application_password
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.2.0 -- npcink-openclaw-adapter read-request status --profile=local --insecure-local-tls READ_REQUEST_ID
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.2.0 -- npcink-openclaw-adapter read-ability --profile=local --insecure-local-tls --ability-id=npcink-abilities-toolkit/wp-ops-diagnostics-detail --input-file=/tmp/read-input.json --read-request-id=READ_REQUEST_ID
```

For generated page visuals, the local CLI includes a bounded
`recipe ai-image-ratio-crop-media-adoption` helper. It reads `/help`, verifies
`openclaw_recipes.ai_image_ratio_crop_media_adoption`, accepts a reviewed
preview URL produced by Cloud Addon or Cloud tooling, and can call
`npcink-abilities-toolkit/build-media-adoption-enhancement-plan` for the
reviewed preview URL. It submits `/proposals/from-plan` only when
`--submit-proposal` is present, and it never approves or executes the final
proposal. Cloud crop, run polling, artifact preview, and derivative payload
building do not belong to Adapter CLI.

```bash
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.2.0 -- npcink-openclaw-adapter recipe ai-image-ratio-crop-media-adoption inspect --profile=local --insecure-local-tls
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.2.0 -- npcink-openclaw-adapter recipe ai-image-ratio-crop-media-adoption adoption-plan --profile=local --insecure-local-tls --preview-url=PREVIEW_URL --post-id=7424 --old-url=OLD_URL --title="WordPress AI hero" --alt-text="WordPress AI proposal workflow hero" --source-type=ai_generated --attribution-text="AI-generated image reviewed before adoption"
```

The request command accepts only Adapter-relative routes such as `/health`,
signs the request locally, and prints only the Adapter JSON response. It also
accepts `--body-stdin` for non-secret POST bodies. Final Adapter write routes
such as `/proposals/{proposal_id}/execute`, `/execute-approved-proposal`, and
`/proposals/{proposal_id}/approve-and-execute` require `--intent=commit`.
The CLI refuses those routes when the body still contains preview markers such
as `dry_run=true`, `commit=false`, or `commit_execution=false`; for dry-run or
preflight-only validation, use `/proposals/{proposal_id}/commit-preflight` with
`--intent=preflight` and stop there.
CLI output is redacted by default for local profile paths, key ids, connection
ids, public/private keys, signatures, authorization headers, cookies, tokens,
passwords, and secrets. Adapter also returns a machine-readable `client_policy`
on `/connection/manifest`, `/health`, and `/help`; local AI clients should read
that policy before selecting routes.
The user-facing local client entrypoint is the published npm CLI. The repository
does not keep root-level `tools/` compatibility wrappers; use the package
directly:

```bash
npm exec --yes --package @npcink/openclaw-adapter-cli@0.2.0 -- npcink-openclaw-adapter status --profile=local --insecure-local-tls
```

See [`docs/keypair-device-pairing-contract.md`](docs/keypair-device-pairing-contract.md)
for the public-key pairing and request-signing contract.
See [`docs/local-ai-client-policy.md`](docs/local-ai-client-policy.md)
for the machine-readable `client_policy` contract and the boundary between
Adapter-owned controls and customer-selected AI clients.
See [`docs/external-ai-client-contract.md`](docs/external-ai-client-contract.md)
for the minimum integration contract for OpenClaw-compatible clients such as
OpenClaw, WorkBuddy, and Qclaw.

When the current site URL is local (`localhost`, loopback, or `.local`), the
handoff form can include `NPCINK_OPENCLAW_ADAPTER_INSECURE_SSL=true` in copied
OpenClaw client configuration. This only affects the generated client env text;
it does not change WordPress or Adapter server-side TLS behavior.

For local setup steps, see
[`docs/openclaw-quickstart.md`](docs/openclaw-quickstart.md).

For the connection-model decision history and guardrails, see
[`docs/openclaw-connection-model-notes.md`](docs/openclaw-connection-model-notes.md).

For the OpenClaw article draft planning recipe, use
[`docs/openclaw-article-draft-plan-recipe.md`](docs/openclaw-article-draft-plan-recipe.md).

For the OpenClaw article batch draft planning recipe, use
[`docs/openclaw-article-batch-draft-plan-recipe.md`](docs/openclaw-article-batch-draft-plan-recipe.md).

For the OpenClaw image candidate adoption recipe, use
[`docs/openclaw-image-candidate-adoption-plan-recipe.md`](docs/openclaw-image-candidate-adoption-plan-recipe.md).

For the OpenClaw SEO/AEO/GEO suggestion recipe, use
[`docs/openclaw-content-discoverability-recipe.md`](docs/openclaw-content-discoverability-recipe.md).
The primary SEO/GEO/AEO entrypoint is
`npcink-toolbox/build-content-discoverability-brief` through
`POST /run-read-ability`.
Use `article-writing-pack` only for broad natural-language requests such as
"help me write an article".

For broad natural-language article requests, use
[`docs/openclaw-ai-article-writing-pack-recipe.md`](docs/openclaw-ai-article-writing-pack-recipe.md).
Call `POST /run-read-ability` with `npcink-toolbox/build-ai-article-writing-pack`
to return a suggestion-only writing pack for a local OpenClaw review candidate.
This is an article assistant path, not an article generator, Cloud writer, or
batch publishing surface.

For productized OpenClaw acceptance, use
[`docs/openclaw-consumer-acceptance.md`](docs/openclaw-consumer-acceptance.md).

For the admin page hierarchy and non-goals, use
[`docs/admin-surface-standard.md`](docs/admin-surface-standard.md).

Cloud runtime access belongs to the standalone `npcink-cloud-addon`.
Adapter must not add its own Cloud settings, signing client, or `/cloud/*`
routes. If an OpenClaw flow needs hosted runtime, Adapter should call the Cloud
Addon public PHP seam described in
[`docs/cloud-connector-boundary.md`](docs/cloud-connector-boundary.md).

## Observability

Adapter emits local metadata-only events through
`npcink_openclaw_adapter_observability_event`; the Cloud Addon is the only uploader. Current
canonical Adapter event kinds are `adapter.core.request`,
`adapter.proposal.create`, `adapter.proposal.plan_ingest`,
`adapter.commit.preflight`, `adapter.proposal.execute`,
`adapter.openclaw.dispatch.completed`, and
`adapter.openclaw.dispatch.failed`.

Events may include method, route, status code, latency, stable error code, and
safe ids such as `proposal_id`, `correlation_id`, `ability_id`, or
`adapter_request_id`. They must not include raw OpenClaw requests, raw
responses, proposal input, write input, prompts, generated content, auth
headers, tokens, or secrets.

## OpenClaw Integration

Initial connection:

1. Create a dedicated WordPress administrator Application Password for the
   OpenClaw environment.
2. Give OpenClaw the non-secret connection manifest. Paste the Application
   Password only into OpenClaw's dedicated secret field or credential vault.
3. OpenClaw calls `GET /health` and verifies:
   - `core_capabilities=true`
   - `abilities_catalog=true`
   - `approval_proxy_enabled=false`
   - `approval_surface=npcink_governance_core_admin`
   - `core_proxy_execute=false`
   - `commit_execution=false`
4. OpenClaw calls `GET /capabilities` and uses Core guidance as the only
   governance truth for each `ability_id`.
5. OpenClaw may call `GET /help` to discover adapter route labels, current
   non-goals, and `openclaw_recipes.article_draft_plan`.

Read-only execution:

1. Call `POST /run-read-ability`; Adapter does not expose shortcut aliases for
   these reads.
2. The adapter re-checks Core for the real `ability_id`.
3. The adapter runs only rows where `execution_surface=wp_abilities_rest` and
   `governance_mode=direct_read` or a Core sensitive read grant allows the
   `core_read_authorization_required` read path.
4. The adapter calls WordPress Abilities API and returns a read envelope with
   `read_policy`, `sensitivity`, `redaction_required`, `redaction_applied`,
   `redaction_summary`, `read_audit_mode`, `correlation_id`, `read_context`,
   and `commit_execution=false`.
5. If Core marks the row `direct_read_sensitive` or
   `redaction_required=true`, Adapter applies bounded read-result redaction
   before returning `result`.
6. If Core marks the row with `read_authorization_required=true`,
   `requires_read_authorization=true`,
   `read_policy=core_read_authorization_required`, or
   `authorization_mode=core_read_request`, OpenClaw must create a Core read
   request through Adapter `POST /read-requests`, wait for Core approval, then
   call `POST /run-read-ability` with the same `ability_id`, same `input`, and
   the approved `read_request_id`. Adapter calls Core `read-preflight`
   immediately before execution and verifies `read_authorization_granted=true`,
   `core_authorization_truth=npcink_governance_core`, `ability_id`,
   `approved_input_hash`, expiry, and bounds before reading.
7. Without a valid Core grant, Adapter fails closed with
   `npcink_openclaw_adapter_core_read_authorization_required`. Prompt text,
   chat consent, direct database access, filesystem reads, logs, and custom
   scripts are not substitutes for Core-managed sensitive read authorization.
8. Planning ability output is returned as plan data. `write_actions`,
   `preview`, `risk`, `manual_review`, and
   `skipped_destructive_candidates` are not execution results.

Plan-to-proposal flow:

1. OpenClaw runs one of the direct-read planning abilities:
   `npcink-abilities-toolkit/build-content-inventory-fix-plan`,
   `npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan`,
	   `npcink-abilities-toolkit/build-media-inventory-fix-plan`,
	   `npcink-abilities-toolkit/build-media-reference-repair-plan`, or
	   `npcink-abilities-toolkit/build-media-settings-reference-repair-plan`, or
		   `npcink-abilities-toolkit/build-media-adoption-enhancement-plan`, or
		   `npcink-abilities-toolkit/build-media-rename-plan`, or
		   `npcink-abilities-toolkit/build-article-optimization-apply-plan`, or
		   `npcink-abilities-toolkit/build-block-theme-site-plan`, or
		   `npcink-abilities-toolkit/build-pattern-page-plan`, or
	   `npcink-toolbox/build-article-write-plan`, or
	   `npcink-toolbox/build-article-batch-write-plan`, or
	   `npcink-toolbox/build-article-media-batch-write-plan`, or
	   `npcink-toolbox/build-image-candidate-adoption-plan`, or
	   `npcink-toolbox/build-site-knowledge-review-plan`, or
	   `npcink-toolbox/build-nightly-inspection-review-plan`.
2. The adapter preserves plan fields including `batch_id`, `issue_types`,
   `post_ids`, `attachment_ids`, `write_actions`, `preview`, `risk`,
   `requires_approval`, `commit_execution`, `dry_run`, `manual_review`,
   `skipped_destructive_candidates`, `issue_counts`, and `action_count`.
3. OpenClaw calls `POST /proposals/from-plan` with `plan_ability_id`, `plan`,
   optional `plan_input`, and `caller` metadata.
4. The adapter forwards that payload to Core
   `POST /npcink-governance-core/v1/proposals/from-plan` and preserves Core status.
   Adapter does not promote destructive candidates into executable actions.
	   For the Toolbox article write plan, Adapter still only forwards the
	   reviewed `article_write_plan`; Core validates the plan and Adapter later
	   executes `npcink-abilities-toolkit/create-draft` only after Core approval and
	   commit-preflight. The machine-readable OpenClaw playbook is exposed as
	   `openclaw_recipes.article_draft_plan` from `GET /help`.
	   For reviewed 2-5 article draft batches, use
	   `npcink-toolbox/build-article-batch-write-plan`; Core creates one
	   batch proposal and Adapter later executes only the approved
	   `npcink-abilities-toolkit/create-draft` write actions. The machine-readable playbook is
	   exposed as `openclaw_recipes.article_batch_draft_plan` from `GET /help`.
	   See [OpenClaw Article Batch Draft Plan Recipe](docs/openclaw-article-batch-draft-plan-recipe.md).
	   For reviewed article batches with selected image-source candidates, use
	   `npcink-toolbox/build-article-media-batch-write-plan`; Core creates one
	   batch proposal and Adapter later executes only approved
	   `npcink-abilities-toolkit/create-draft`, `npcink-abilities-toolkit/upload-media-from-url`,
	   `npcink-abilities-toolkit/update-media-details`, and
	   `npcink-abilities-toolkit/set-post-featured-image` actions. The machine-readable
		   playbook is exposed as `openclaw_recipes.article_media_batch_plan` from
		   `GET /help`. See
		   [OpenClaw Article Media Batch Plan Recipe](docs/openclaw-article-media-batch-plan-recipe.md).
		   Before selecting any Gutenberg or block-theme editing recipe, normalize
		   customer wording through `openclaw_recipes.content_intent_router`
		   and the read-only `npcink-abilities-toolkit/route-content-intent`
		   ability. The machine-readable playbook is exposed from `GET /help`
		   and documented in
		   [OpenClaw Content Intent Router Contract](docs/openclaw-content-intent-router-contract.md).
		   The router marks customer prompts as untrusted input
		   (`prompt_is_authorization=false`), defaults unsupported or ambiguous
		   requests to fail closed, and only routes to existing reviewed recipes:
		   `openclaw_recipes.pattern_page_plan`,
		   `openclaw_recipes.article_block_plan`, or
		   `openclaw_recipes.block_theme_site_plan`. Use
		   `openclaw_recipes.site_edit_router` as the narrower Site Editor
		   surface contract.
		   The verified page/article/template routing baseline is captured in
		   [OpenClaw Gutenberg Content Intent Routing Baseline](docs/openclaw-gutenberg-content-intent-routing-baseline.md).
		   Post-content routes use `npcink-abilities-toolkit/get-post-blocks`
		   and `npcink-abilities-toolkit/update-post-blocks`; block-theme routes
		   use `npcink-abilities-toolkit/get-template-blocks`,
		   `npcink-abilities-toolkit/get-template-part-blocks`, and
		   `npcink-abilities-toolkit/inspect-gutenberg-composition-contract`
		   for lightweight post-execution/readback contract checks where
		   readback failure is recorded as verification metadata.
		   See [OpenClaw Site Edit Router Contract](docs/openclaw-site-edit-router-contract.md).
		   For reviewed article optimization excerpt updates, use
		   `npcink-abilities-toolkit/build-article-optimization-apply-plan`;
		   Core creates one proposal and Adapter later executes only approved
		   `npcink-abilities-toolkit/update-post` actions after Core approval and
		   commit-preflight. The planning source remains
		   `npcink-abilities-toolkit/recipes/article-optimization`; direct WordPress writes stay
		   disabled in the plan output.
		   For reviewed Gutenberg page pattern drafts, use
		   `npcink-abilities-toolkit/build-pattern-page-plan`; Core creates one
	   batch proposal and Adapter later executes only approved
	   `npcink-abilities-toolkit/create-draft` and
	   `npcink-abilities-toolkit/update-post-blocks` actions. The machine-readable
	   playbook is exposed as `openclaw_recipes.pattern_page_plan` from
	   `GET /help`, including a `visual_acceptance` block with front-end/editor
		   targets, 1440/768/390 viewport checks, and the local smoke artifact envs.
		   Local smoke also machine-checks the generated draft for non-empty
		   headings, complete image `src`/`alt` attributes,
		   Gutenberg-native spacing on key sections, and
		   post-execution `get-post-blocks` readback verification before any
		   browser review.
		   For modern or repeated landing-page generation, treat visual quality
		   as a design-system contract rather than a one-off template tweak:
		   require recipe variants, section shape variety, reviewed media roles,
		   anti-template checks, and design-quality signals before proposal
		   creation. See
		   [OpenClaw Gutenberg Design System](docs/openclaw-gutenberg-design-system.md).
		   See [OpenClaw Pattern Page Plan Recipe](docs/openclaw-pattern-page-plan-recipe.md)
		   and [OpenClaw Gutenberg Visual Acceptance](docs/openclaw-gutenberg-visual-acceptance.md).
		   For reviewed conversational block theme Site Editor changes, use
		   `npcink-abilities-toolkit/build-block-theme-site-plan`; Core creates one
		   batch proposal and Adapter later executes only approved
		   `npcink-abilities-toolkit/update-template-blocks`,
		   `npcink-abilities-toolkit/upsert-template-blocks`, and
		   `npcink-abilities-toolkit/update-template-part-blocks` actions. The
		   machine-readable playbook is exposed as
		   `openclaw_recipes.block_theme_site_plan` from `GET /help`. The MVP
		   supports `intent=add_breadcrumbs` and keeps global styles, navigation,
		   template creation, and generic Site Editor writes outside Adapter
		   execution profiles. After execution or when the user asks to check a
		   template result, read back the template blocks and call
		   `npcink-abilities-toolkit/inspect-gutenberg-composition-contract`;
		   only `contract_status=needs_revision` should trigger another
		   supported plan. See
		   [OpenClaw Block Theme Site Builder Recipe](docs/openclaw-block-theme-site-builder-recipe.md).
		   For reviewed Gutenberg article block drafts, use
	   `npcink-abilities-toolkit/build-article-block-plan`; Core creates one
	   batch proposal and Adapter later executes only approved
	   `npcink-abilities-toolkit/create-draft` and
	   `npcink-abilities-toolkit/update-post-blocks` actions. The machine-readable
	   playbook is exposed as `openclaw_recipes.article_block_plan` from
	   `GET /help`, including the same browser visual acceptance contract for
	   responsive Gutenberg article drafts. See
	   [OpenClaw Article Block Plan Recipe](docs/openclaw-article-block-plan-recipe.md)
	   and [OpenClaw Gutenberg Visual Acceptance](docs/openclaw-gutenberg-visual-acceptance.md).
	   For adopting one reviewed `image_candidate.v1` into the media library,
	   call `npcink-toolbox/build-image-candidate-adoption-plan`; Core
	   creates one batch proposal and Adapter later executes only approved
	   `npcink-abilities-toolkit/upload-media-from-url`, `npcink-abilities-toolkit/update-media-details`, and
	   optional `npcink-abilities-toolkit/set-post-featured-image` actions. The
	   machine-readable playbook is exposed as
	   `openclaw_recipes.image_candidate_adoption_plan` from `GET /help`. See
	   [OpenClaw Image Candidate Adoption Plan Recipe](docs/openclaw-image-candidate-adoption-plan-recipe.md).
	   For one reviewed remote visual asset that should be imported, optimized,
	   and optionally wired into an existing page/post reference, call
	   `npcink-abilities-toolkit/build-media-adoption-enhancement-plan`; Core
	   creates one batch proposal and Adapter later executes only approved
	   `npcink-abilities-toolkit/upload-media-from-url`,
	   `npcink-abilities-toolkit/optimize-media-asset`, and optional
	   `npcink-abilities-toolkit/patch-post-content` actions. The
	   machine-readable playbook is exposed as
	   `openclaw_recipes.media_adoption_enhancement_plan` from `GET /help`.
	   See [OpenClaw Media Adoption Enhancement Plan Recipe](docs/openclaw-media-adoption-enhancement-plan-recipe.md).
	   For research-backed Gutenberg landing pages, first use
	   `openclaw_recipes.pattern_page_research_brief` to request bounded
	   Cloud-owned `competitor_research` evidence through Toolbox and produce a
	   suggestion-only `landing_page_research_brief`. See
	   [OpenClaw Pattern Page Research Brief Recipe](docs/openclaw-pattern-page-research-brief-recipe.md).
	   For visually richer Gutenberg landing pages, compose the reviewed image
	   candidate adoption flow with `pattern_page_plan`: first ask the
	   Cloud-backed image source recommender for a fitting candidate, use
	   hosted AI image generation only when no reviewable recommendation fits,
	   crop and convert the selected candidate through the Cloud media derivative path,
	   adopt the processed result into the local media library through Core, then
	   pass the approved WordPress media URL as `variables.hero_media_url` with
	   `media_strategy=existing_media_url`. The machine-readable playbook is
	   exposed as `openclaw_recipes.pattern_page_with_visual_asset_plan` from
	   `GET /help`. See
	   [OpenClaw Pattern Page With Visual Asset Recipe](docs/openclaw-pattern-page-with-visual-asset-recipe.md).
	   For AI-generated visuals whose model output dimensions are unreliable,
	   choose the page-slot ratio first, prefer existing Cloud-recommended
	   candidates when available, crop the reviewed candidate through the Cloud
	   media derivative path, then adopt the cropped preview through
	   `npcink-abilities-toolkit/build-media-adoption-enhancement-plan` before a
	   page references the final local media URL. The machine-readable playbook
	   is exposed as `openclaw_recipes.ai_image_ratio_crop_media_adoption` from
	   `GET /help`. See
	   [OpenClaw AI Image Ratio Crop Media Adoption Recipe](docs/openclaw-ai-image-ratio-crop-media-adoption-recipe.md).
	   For Site Knowledge agent evidence review, call
	   `npcink-toolbox/build-site-knowledge-review-plan`; Core creates a
	   blocked review proposal that still requires human `title` and `content`
	   input. Adapter must not approve, preflight, execute, or write WordPress
	   content from this handoff.
	   For Nightly Inspection Morning Brief review, call
	   `npcink-toolbox/build-nightly-inspection-review-plan`; Core creates a
	   blocked review proposal from selected review evidence only. Adapter must
	   not approve, preflight, execute, or write WordPress content from this
	   handoff.

Proposal-required write flow:

1. OpenClaw reads `/capabilities` and selects a real ability where Core reports
   `governance_mode=proposal_required`.
2. OpenClaw calls `POST /proposals` with the real `ability_id`, `input`,
   `preview`, and `caller` metadata.
3. Core stores the proposal and WordPress approval state.
4. OpenClaw polls `GET /proposals/{proposal_id}` through the adapter until Core
   returns an approved or rejected status. `GET /proposals?limit=...` is
   available for list views.
5. For the unified user path, OpenClaw calls
   `POST /proposals/{proposal_id}/approve-and-execute` so the user approves
   and executes from the Adapter/OpenClaw entry point. Core remains the
   governance backend for approval, commit-preflight, and audit.
6. If `status=rejected`, OpenClaw stops and shows the rejection state or reason
   returned by Core.
7. If `status=approved` and execution is intended, OpenClaw calls
   `POST /proposals/{proposal_id}/execute` or `POST /execute-approved-proposal`.
   Adapter execute routes are final write paths and normalize ability input to
   `dry_run=false` and `commit=true`. Do not call Core commit-preflight
   directly; Core handoffs are one-time.
8. `POST /proposals/{proposal_id}/commit-preflight` is an advanced diagnostic
   Adapter route. When it succeeds through Adapter, Adapter caches the one-time
   Core handoff for the next Adapter execute call and still preserves
   `commit_execution=false`. For dry-run-only verification, stop here and do
   not call execute.
9. For the current approved proposal execution path, Adapter may execute only
   `npcink-abilities-toolkit/trash-post`, `npcink-abilities-toolkit/create-draft`,
   `npcink-abilities-toolkit/update-post`, `npcink-abilities-toolkit/set-post-seo-meta`,
   `npcink-abilities-toolkit/set-post-slug`, `npcink-abilities-toolkit/set-post-terms`,
	   `npcink-abilities-toolkit/delete-term`, `npcink-abilities-toolkit/patch-post-content`,
	   `npcink-abilities-toolkit/update-post-blocks`,
	   `npcink-abilities-toolkit/update-template-blocks`,
	   `npcink-abilities-toolkit/upsert-template-blocks`,
	   `npcink-abilities-toolkit/update-template-part-blocks`,
	   `npcink-abilities-toolkit/patch-setting-value`,
	   `npcink-abilities-toolkit/update-media-details`,
	   `npcink-abilities-toolkit/optimize-media-asset`,
	   `npcink-abilities-toolkit/replace-media-file`,
	   `npcink-abilities-toolkit/restore-media-backup`,
	   `npcink-abilities-toolkit/adopt-cloud-media-derivative`,
	   `npcink-abilities-toolkit/rename-media-file`,
	   `npcink-abilities-toolkit/delete-media-permanently`, `npcink-abilities-toolkit/reply-comment`,
   `npcink-abilities-toolkit/trash-comment`, or `npcink-abilities-toolkit/approve-comment` through
   `POST /proposals/{proposal_id}/execute` or
   `POST /execute-approved-proposal`.
10. Adapter fetches the Core proposal, consumes a cached Adapter preflight
    handoff when one was issued through Adapter, otherwise calls Core
    commit-preflight, requires `approval_commit_authorized=true`, requires
    `commit_execution=false`, passes Core `approval_context` to WordPress
    Abilities API, normalizes ability input to `dry_run=false` and
    `commit=true`, stores a bounded execution record, and returns `proposal_id`,
    `correlation_id`, `ability_id`, and `execution_record` with the ability
    result. For approved block writes, Adapter performs a bounded post-execution readback before storing the execution record: `update-post-blocks` uses
    `npcink-abilities-toolkit/get-post-blocks`, template writes use
    `npcink-abilities-toolkit/get-template-blocks`, and template part writes use
    `npcink-abilities-toolkit/get-template-part-blocks`. A readback failure is recorded as verification metadata; it does not create a second write path or
    retry queue. If execution fails after Core preflight has been consumed, Adapter
    stores and returns only a bounded failed execution summary; it does not
    store the full proposal or create a retry queue. Repeating the same
    successful proposal execution returns
    `npcink_openclaw_adapter_execution_already_completed` and does not run the
    ability again.
11. Adapter does not create its own proposal or approval state and does not
    batch silently execute destructive actions. The unified action only
    orchestrates Core approve -> commit-preflight -> one allowlisted WordPress
    Abilities API execution.

Proposal list/detail are read-only Core proxies. They preserve Core response
fields such as `proposal_id`, `ability_id`, `status`, `title`, `summary`,
`input`, `preview`, `caller`, `created_at`, `updated_at`, and detail
`audit_timeline` when Core returns it. Adapter may be configured with a Core app
token through `NPCINK_OPENCLAW_ADAPTER_CORE_APP_TOKEN` or the
`npcink_openclaw_adapter_core_app_token` option. When configured, Adapter sends that
token only on internal Core REST requests and does not print it. That key must
include `proposals:read` for proposal status, plus the other scopes needed by
the Core routes Adapter calls. The adapter must not print Core tokens in logs,
proposal payloads, error responses, or documentation examples.

When OpenClaw has a Core `proposal_id` or commit-preflight `correlation_id`, it
should pass those values to Adapter read or future execution requests as
`log_context` or query parameters. Adapter will include them under
`npcink_openclaw_adapter`, top-level context fields, and nested `npcink_governance_core`
context for provider request log correlation.

Adapter does not expose a provider/model smoke route. Provider credentials,
model routing, prompt execution, and AI Request Logs provider calls belong to
the AI client, Cloud/Add-on runtime, or the relevant provider integration.
Adapter only carries bounded request context such as `adapter_request_id`,
`proposal_id`, `correlation_id`, and nested Core context for observability.

`POST /proposals/{proposal_id}/approve` and
`POST /proposals/{proposal_id}/reject` are disabled stubs. They return HTTP 403
with `code=npcink_openclaw_adapter_approval_proxy_disabled`,
`approval_proxy_enabled=false`, and
`approval_surface=npcink_governance_core_admin`. For the Adapter/OpenClaw unified user
action, use `POST /proposals/{proposal_id}/approve-and-execute`; otherwise use
Npcink Governance Core admin for split approval decisions. The adapter does not forward
the standalone approve/reject stub routes to Core and does not require a
default Core key with approval or rejection scopes.

Example health request:

```bash
curl -sS --user "OPENCLAW_USERNAME:<openclaw-secret-field-value>" \
  "https://example.test/wp-json/npcink-openclaw-adapter/v1/health"
```

Example proposal request:

```bash
curl -sS --user "OPENCLAW_USERNAME:<openclaw-secret-field-value>" \
  -H "Content-Type: application/json" \
  -d '{"ability_id":"npcink-abilities-toolkit/create-draft","title":"Draft proposal","summary":"OpenClaw requests a governed draft proposal.","input":{"title":"OpenClaw draft","dry_run":true,"commit":false},"preview":{},"caller":{"external_thread_id":"OPENCLAW_THREAD_ID"}}' \
  "https://example.test/wp-json/npcink-openclaw-adapter/v1/proposals"
```

Example proposal status request:

```bash
curl -sS --user "OPENCLAW_USERNAME:<openclaw-secret-field-value>" \
  "https://example.test/wp-json/npcink-openclaw-adapter/v1/proposals/PROPOSAL_ID"
```

## OpenClaw Flow

Read-only abilities:

1. OpenClaw calls Adapter `/capabilities`.
2. Adapter relays Core guidance.
3. If `governance_mode=direct_read`, OpenClaw calls Adapter read endpoints.
4. If Core requires sensitive read authorization, OpenClaw calls Adapter
   `/read-requests`, waits for Core approval, then calls `/run-read-ability`
   with `read_request_id`.
5. Adapter runs the ability through WordPress Abilities API only after any
   required Core read-preflight grant succeeds.

Write or destructive abilities:

1. OpenClaw calls Adapter `/capabilities`.
2. If `governance_mode=proposal_required`, OpenClaw calls Adapter
   `/proposals`.
3. OpenClaw polls Adapter `/proposals/{proposal_id}` for Core status.
4. If pending and the user chooses the unified action, OpenClaw calls
   Adapter `/proposals/{proposal_id}/approve-and-execute`; Adapter calls Core
   approve, Core commit-preflight, then executes one allowlisted final write.
5. If rejected, OpenClaw stops and shows the status. If approved, OpenClaw calls
   Adapter `/proposals/{proposal_id}/commit-preflight` after
   approval.
6. Adapter relays Core `commit_execution=false`. Dry-run-only verification
   stops at Adapter commit-preflight; do not call execute unless the operator
   intends a final write.
7. For approved proposal execution, only `npcink-abilities-toolkit/trash-post`,
	   `npcink-abilities-toolkit/create-draft`, `npcink-abilities-toolkit/update-post`,
	   `npcink-abilities-toolkit/patch-post-content`, `npcink-abilities-toolkit/update-post-blocks`,
	   `npcink-abilities-toolkit/update-template-blocks`,
	   `npcink-abilities-toolkit/upsert-template-blocks`,
	   `npcink-abilities-toolkit/update-template-part-blocks`,
	   `npcink-abilities-toolkit/patch-setting-value`,
   `npcink-abilities-toolkit/set-post-seo-meta`, `npcink-abilities-toolkit/set-post-slug`,
	   `npcink-abilities-toolkit/set-post-terms`, `npcink-abilities-toolkit/delete-term`,
	   `npcink-abilities-toolkit/update-media-details`, `npcink-abilities-toolkit/optimize-media-asset`,
	   `npcink-abilities-toolkit/replace-media-file`,
	   `npcink-abilities-toolkit/restore-media-backup`,
	   `npcink-abilities-toolkit/adopt-cloud-media-derivative`,
	   `npcink-abilities-toolkit/rename-media-file`,
	   `npcink-abilities-toolkit/delete-media-permanently`,
   `npcink-abilities-toolkit/reply-comment`, `npcink-abilities-toolkit/trash-comment`, and
   `npcink-abilities-toolkit/approve-comment` are supported in
   this adapter. The execution input may be a single allowlisted proposal input or a bounded
   `input.write_actions[]` batch where every action targets the allowlist.
   OpenClaw calls `/proposals/{proposal_id}/execute`; Adapter performs Core
   preflight again, passes `approval_context`, normalizes ability input to
   `dry_run=false` and `commit=true`, and executes through WordPress Abilities
   API. New execution abilities must be added as explicit Adapter
   execution profile entries with dedicated smoke coverage; this is not a
   generic proxy-execute surface.

Adapter derives the execution allowlist from its local execution profile registry.
Capability discovery may show more proposal-required abilities, but
only abilities with an Adapter execution profile can run final writes.
The registry stays in Adapter as post-Core execution policy. It is not migrated
to Toolkit or Core, and it must not be extended through filters, options,
database rows, remote configuration, wildcards, category matches, or arbitrary
ability ids.
Each profile is an explicit post-Core policy entry, not a generic executor: it
must bind one ability id, require Core approval plus commit-preflight, accept
only declared fields, validate required ids/enums/sizes, normalize explicit
commit intent, and fail closed on any undeclared write shape.
For profiled abilities, Adapter also validates proposal input at
`POST /proposals`, rejecting undeclared fields and invalid enum values before
the proposal is sent to Core. The same Adapter-owned input schema check also
runs for profiled `plan.write_actions[]` during `POST /proposals/from-plan`
before Adapter forwards the plan to Core; invalid actions return
`npcink_openclaw_adapter_plan_action_input_invalid` with `blocked_items[]` carrying
the action index, action id, target ability id, blocked field, and reused
single-proposal block code. Exact `$outputs.<prior_action_id>.<field>`
references are allowed in profiled plan action input only when they point to an
earlier action in the same plan; Adapter revalidates the resolved value during
approved batch execution. Embedded `$outputs.` tokens are rejected, and plan
action ids must be unique before Adapter forwards the plan to Core.
Within one approved `write_actions[]` batch, later actions may reference earlier
action outputs with exact values such as `$outputs.create-draft.post_id`.
Adapter resolves those references in memory during that batch only, then
revalidates the resolved action input before execution.

## Non-Goals

This plugin must not become:

- an ability registry;
- an approval store;
- a workflow runtime;
- an MCP server;
- an Agent Gateway catalog;
- a generic final write executor;
- a replacement for WordPress Abilities API.

## Development

Run static checks:

```bash
composer test:all
```

Run Plugin Check against the release/package surface:

```bash
composer plugin-check:release
```

Build a release zip with the same package boundary:

```bash
composer package:release
```

Run the package install smoke against the generated zip:

```bash
composer smoke:package-install
```

This temporarily installs `build/npcink-ai-client-adapter.zip` into the
configured local WordPress site, verifies that only `Npcink AI Client Adapter`
is visible as a plugin, checks that the removed legacy bootstrap is not
packaged, confirms the compatible REST namespace, and restores the previous local
plugin directory or symlink on exit.

The release surface is defined by `.distignore`. It excludes development-only
artifacts such as `tests/`, `AGENTS.md`, `.gitignore`, Composer metadata, and
local dependency folders from package-oriented Plugin Check runs. Do not delete
those files from the source tree just to satisfy a full-worktree PCP scan.

Run the LocalWP smoke test:

```bash
composer smoke:wp
```

Run the non-destructive local AI client acceptance pass for an existing signed
CLI profile:

```bash
MAA_ADAPTER_ACCEPTANCE_PROFILE=local composer accept:local-ai-client
```

Run the signed local AI client fixture acceptance pass:

```bash
MAA_ADAPTER_ACCEPTANCE_PROFILE=local composer accept:local-ai-client-fixture
```

By default this creates and reads a Core proposal, then verifies that the CLI
refuses final Adapter execution without `--intent=commit`. To run the final
approve-and-execute fixture, set `MAA_ADAPTER_FIXTURE_ALLOW_COMMIT=1`; the
script deletes the created draft post with WP-CLI by default.

Set `MAA_ADAPTER_ACCEPTANCE_SENSITIVE_READ_*`,
`MAA_ADAPTER_ACCEPTANCE_PREFLIGHT_PROPOSAL_ID`, or
`MAA_ADAPTER_ACCEPTANCE_COMMIT_PROPOSAL_ID` only for explicit sensitive-read,
preflight, or final-write acceptance. See
[`docs/local-ai-client-acceptance.md`](docs/local-ai-client-acceptance.md).

Run the retained-fixture browser visual acceptance pass for Gutenberg page and
article fixtures:

```bash
composer visual:wp
```

The browser runner writes screenshots and a JSON report to
`build/visual-acceptance/`. Set
`MAA_ADAPTER_VISUAL_ACCEPTANCE_SKIP_SMOKE=1` to reuse an existing retained
fixture manifest instead of creating new smoke fixtures. During this local-only
pass, retained smoke fixtures are temporarily published so anonymous browser
rendering verifies the generated Gutenberg content rather than a theme 404 page;
the smoke path still asserts the governed execution creates draft content first.
Set `MAA_ADAPTER_VISUAL_ACCEPTANCE_CREATE_TEMP_ADMIN=1` to create a temporary
local administrator for the editor invalid-block check; the wrapper deletes that
user on exit and does not print the generated password.

Local smoke and HTTP acceptance tests must register every created fixture for
automatic cleanup before assertions can fail. Rejected and preflight-blocked
negative-loop cases must not rely on final write execution to remove target
posts, because the correct behavior is to leave those writes unexecuted.
The Gutenberg page/article smoke path additionally asserts that approved
block-write proposals persist compact execution verification, post-content
readback reports no failed block reads, image markup has non-empty `src` and
`alt`, generated headings and paragraphs are not blank, and key sections retain
Gutenberg-native spacing. Set `MAA_ADAPTER_KEEP_VISUAL_ACCEPTANCE_FIXTURES=1`
with `MAA_ADAPTER_VISUAL_ACCEPTANCE_OUT=/path/to/manifest.json` when a retained
fixture is needed for browser viewport review.
