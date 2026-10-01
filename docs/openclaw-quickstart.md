# OpenClaw Quickstart

Status: local development handoff guide.

This guide is for connecting an OpenClaw-compatible local AI client to a local
WordPress development site through Npcink OpenClaw Adapter. The adapter remains
a thin channel layer:

- the local AI client connects to Adapter;
- Npcink Governance Core is Adapter's governance service behind the scenes;
- Core remains the approval, preflight, and audit truth source;
- read operations go through WordPress Abilities API;
- write-like operations create Core proposals; a human approves them in the
  Core admin, and the signed client then executes one supported write through
  Adapter's execute route (the unified approve-and-execute action exists for
  WordPress administrator sessions only);
- `core_proxy_execute=false`;
- `approval_surface=npcink_governance_core_admin`;
- `core_proxy_execute=false`;
- `commit_execution=false`.

For the acceptance checklist that productized clients should run
before relying on the connection, see
[`openclaw-consumer-acceptance.md`](openclaw-consumer-acceptance.md).
For the connection-model decision notes and guardrails for future agents, see
[`openclaw-connection-model-notes.md`](openclaw-connection-model-notes.md).

## Local WordPress Access

Current LocalWP development site:

- Site URL: `https://npcink.local`
- WordPress admin URL: `https://npcink.local/wp-admin/`
- WordPress administrator username: `1`
- WordPress administrator password: `1`

These credentials are for the local development environment only. Do not reuse
them for production, hosted test sites, shared staging sites, or customer data.

Use the username/password above for browser login to WordPress admin. For REST
handoff to a local AI client, create a dedicated WordPress Application Password
for the same local administrator account. Pass only the non-secret connection
manifest to the client, and paste the Application Password only into the
client's dedicated secret field or credential vault.

## Adapter URLs

Base URL:

```text
https://npcink.local/wp-json/npcink-openclaw-adapter/v1
```

Health:

```text
GET https://npcink.local/wp-json/npcink-openclaw-adapter/v1/health
```

Help:

```text
GET https://npcink.local/wp-json/npcink-openclaw-adapter/v1/help
```

Capabilities:

```text
GET https://npcink.local/wp-json/npcink-openclaw-adapter/v1/capabilities
```

WordPress admin connection page:

```text
Npcink -> Adapter
```

The page defaults to the simple Application Password flow for clients that have
a dedicated password, credential, or secret field. It can create a normal
WordPress Application Password for the current administrator and show the raw
password once in the browser. Copied client env, manifest, and handoff text
contain only placeholders or non-secret identifiers. The adapter does not store
the raw password.

The page also shows a higher-security signed key-pair flow. That flow provides
an npm CLI connect command, status command, local client request instructions, and
authorized public key management. The CLI generates the private key locally;
Adapter stores only the approved public key.

The same page includes a `Proposal status` lookup. Paste the `Proposal ID`
returned by Adapter after `POST /proposals` or `POST /proposals/from-plan` to
read Core status through Adapter, open the matching Core approval detail, and
copy the Adapter status or execution route for the local AI client. Pending approvals
remain in Core; Adapter is the status and approved-execution channel.

## REST Authentication

OpenClaw-compatible clients may use WordPress REST Basic Auth with an
Application Password:

```text
Authorization: Basic base64(username:<openclaw-secret-field-value>)
```

Example:

```bash
curl -sS --user "1:<openclaw-secret-field-value>" \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/health"
```

Do not put the normal WordPress login password in OpenClaw configuration when
an Application Password is available. Do not paste the Application Password
into chat, tool commands, logs, proposal payloads, files, or copied handoff
text.

## Public Key Device Pairing

For WorkBuddy or another local broker, use key-pair device pairing instead of
sending a browser-visible Application Password:

```text
GET  /connection/manifest
POST /connect/device/start
POST /connect/device/poll
GET  /connection/key-pairs
```

The local broker generates an Ed25519 key pair, keeps the private key local,
and starts pairing with the public key. WordPress shows an admin approval page;
after approval, Adapter stores only the public key and later verifies signed
Adapter requests.

For local validation, run the npm CLI on the same machine or execution
environment as OpenClaw:

```bash
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.4.0 -- npcink-openclaw-adapter connect --site=https://npcink.local --profile=local --insecure-local-tls
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.4.0 -- npcink-openclaw-adapter status --profile=local --insecure-local-tls
```

The script opens the WordPress approval URL in the system browser. Approve the
client there; the script then stores a local profile under
`~/.npcink-openclaw-adapter/keypair-profiles/` and tests a signed `GET /health`. Use
`--no-open` to print the URL without opening a browser. A production WorkBuddy
integration should replace that file write with the OS keychain or WorkBuddy
credential vault. Use `--insecure-local-tls` only for LocalWP or `.local`
self-signed HTTPS testing. If LocalWP resets a polling connection, the verifier
keeps retrying until the pairing code expires.

After approval, WordPress shows a pairing result page. Return to the terminal or
local AI client and wait for the polling command to finish.

After pairing, local clients should call Adapter through the
local request wrapper instead of reading the profile file:

```bash
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.4.0 -- npcink-openclaw-adapter request --profile=local --insecure-local-tls GET /health
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.4.0 -- npcink-openclaw-adapter request --profile=local --insecure-local-tls GET /capabilities
```

For POST requests, write the non-secret request JSON to a temporary file and
pass it with `--body-file`, or pass non-secret JSON through stdin:

```bash
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.4.0 -- npcink-openclaw-adapter request --profile=local --insecure-local-tls POST /proposals/from-plan --body-file=/tmp/npcink-proposal.json
printf '%s' '{"plan":{}}' | (cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.4.0 -- npcink-openclaw-adapter request --profile=local --insecure-local-tls POST /proposals/from-plan --body-stdin)
```

The wrapper rejects absolute URLs, signs the Adapter-relative route locally, and
prints only the Adapter JSON response. Do not ask the client to read or summarize
`~/.npcink-openclaw-adapter/keypair-profiles/*.json`.
The user-facing local client entrypoint is the published npm CLI. The repository
does not keep root-level `tools/` compatibility wrappers.

Use the package directly:

```bash
npm exec --yes --package @npcink/openclaw-adapter-cli@0.4.0 -- npcink-openclaw-adapter status --profile=local --insecure-local-tls
```

Administrators manage authorized public keys from `Npcink -> Adapter` in the
higher-security signed key-pair section. Revoke a key there to stop the
corresponding local profile from authenticating.

### `GET /health` returns `401 rest_forbidden`

`GET /health` is a private Adapter route. A raw unauthenticated request, such as
direct `curl` without the signed local request wrapper, should be rejected by
WordPress REST before any Toolkit, Core, composer, proposal, or execution step
runs. Treat that `401 rest_forbidden` response as an authentication failure, not
as evidence that `route-content-intent`, the Gutenberg block catalog, or Core
proposal intake failed.

For local OpenClaw validation, first confirm the same profile works through the
signed wrapper:

```bash
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.4.0 -- npcink-openclaw-adapter status --profile=local --insecure-local-tls
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.4.0 -- npcink-openclaw-adapter request --profile=local --insecure-local-tls GET /health
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.4.0 -- npcink-openclaw-adapter request --profile=local --insecure-local-tls GET /help
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.4.0 -- npcink-openclaw-adapter request --profile=local --insecure-local-tls GET /capabilities
```

Use `--insecure-local-tls` only for LocalWP or other `.local` self-signed HTTPS
testing. If the same command fails with `self-signed certificate`, retry with
that flag or configure a trusted local CA. If it fails with `rest_forbidden`,
re-pair the profile or verify in WordPress that the stored public key is not
revoked and belongs to an administrator-capable user.

Do not treat a displayed `scopes_effective` list as proof that the actual REST
request was signed. If `status --profile=local --insecure-local-tls` reports
ready, but OpenClaw still receives `401 rest_forbidden`, the likely causes are:

- OpenClaw bypassed the local signed request wrapper.
- OpenClaw used a different profile or profile path.
- OpenClaw omitted the LocalWP TLS option.
- OpenClaw sent an unsigned or incorrectly signed Adapter-relative request.

Never read, print, summarize, or copy
`~/.npcink-openclaw-adapter/keypair-profiles/*.json`. Use the CLI wrapper or an
equivalent Ed25519 signing implementation instead.

## Connection Check

1. Call `GET /health`.
2. Confirm:
   - `core_capabilities=true`
   - `abilities_catalog=true`
   - `approval_surface=npcink_governance_core_admin`
   - `core_proxy_execute=false`
   - `commit_execution=false`
3. Call `GET /help` to confirm route discovery includes proposal list/detail,
   `POST /proposals/from-plan`, and `POST /proposals/{proposal_id}/execute`
   (`POST /proposals/{proposal_id}/approve-and-execute` is listed for
   administrator sessions only). For article drafting,
   read `openclaw_recipes.article_draft_plan`.
4. Call `GET /capabilities`.
5. Use the returned Core guidance as the only governance truth.

## Read Abilities

Use the generic `POST /run-read-ability` route. Sensitive abilities such as
`site-info` and content inventory planning require an approved Core read
request; they must not be accessed by shortcut URLs or direct WordPress
internals. Create a request bound to the exact ability input first:

```bash
curl -sS --user "1:<openclaw-secret-field-value>" \
  -H "Content-Type: application/json" \
  -d '{"ability_id":"npcink-abilities-toolkit/build-content-inventory-fix-plan","input":{"per_page":1,"max_actions":1},"requested_input_summary":"Bounded content inventory plan","data_classes":["site_content"],"purpose":"Review content inventory fixes","redaction_level":"strict","bounds":{"max_rows":10}}' \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/read-requests"
```

An administrator must approve that request in Core. Check its status, then
repeat the exact ability input with the approved request id:

```bash
curl -sS --user "1:<openclaw-secret-field-value>" \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/read-requests/READ_REQUEST_ID"
```

```bash
curl -sS --user "1:<openclaw-secret-field-value>" \
  -H "Content-Type: application/json" \
  -d '{"ability_id":"npcink-abilities-toolkit/build-content-inventory-fix-plan","input":{"per_page":1,"max_actions":1},"read_request_id":"READ_REQUEST_ID"}' \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/run-read-ability"
```

Treat returned `write_actions` and `preview` as proposal input, not as completed writes.
Send the plan to Core through Adapter when a proposal should be created:

```bash
curl -sS --user "1:<openclaw-secret-field-value>" \
  -H "Content-Type: application/json" \
  -d '{"plan_ability_id":"npcink-abilities-toolkit/build-content-inventory-fix-plan","plan":{"batch_id":"example","issue_types":[],"requires_approval":true,"commit_execution":false,"dry_run":true,"action_count":0,"write_actions":[],"preview":[],"risk":{"level":"medium"}},"plan_input":{"per_page":1},"caller":{"external_thread_id":"OPENCLAW_THREAD"}}' \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/proposals/from-plan"
```

For reviewed article draft planning, use the recipe exposed by `GET /help` at
`openclaw_recipes.article_draft_plan`. The entrypoint ability is
`npcink-toolbox/build-article-write-plan`; the final governed write remains
`npcink-abilities-toolkit/create-draft` after Core approval and commit preflight. See
[`openclaw-article-draft-plan-recipe.md`](openclaw-article-draft-plan-recipe.md).

For reviewed 2-5 article draft batches, use `GET /help` at
`openclaw_recipes.article_batch_draft_plan`. The entrypoint ability is
`npcink-toolbox/build-article-batch-write-plan`; Core creates one batch
proposal, and the final governed writes remain draft-only
`npcink-abilities-toolkit/create-draft` actions after Core approval and commit preflight. See
[`openclaw-article-batch-draft-plan-recipe.md`](openclaw-article-batch-draft-plan-recipe.md).

Troubleshooting diagnostics:

```bash
curl -sS --user "1:<openclaw-secret-field-value>" \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/active-plugins-detail"
```

The default diagnostics input requests active plugins, update rows, must-use
plugins, dropins, and log severity summaries, but it does not request inactive
plugin rows:

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

For plugin conflict troubleshooting, explicitly request inactive plugin rows:

```bash
curl -sS --user "1:<openclaw-secret-field-value>" \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/plugin-conflict-diagnostics"
```

That route sends `include_inactive_plugins=true` and
`max_plugins_per_group=200`.

```bash
curl -sS --user "1:<openclaw-secret-field-value>" \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/recent-error-log"
```

`recent-error-log` uses `include_log_contents=false`. Treat log contents as
not explicitly requested, not missing. Use `error_log.summary.fatal_count`,
`error_log.summary.error_count`, `error_log.summary.warning_count`,
`error_log.summary.deprecated_count`, `error_log.summary.notice_count`, and
`error_log.summary.by_severity` for severity display without fetching content.
When the user explicitly asks to inspect logs, request the bounded redacted
tail:

```bash
curl -sS --user "1:<openclaw-secret-field-value>" \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/recent-error-log-tail"
```

That route sends:

```json
{
  "include_log_contents": true,
  "tail_lines": 50,
  "severity": ["fatal", "error", "warning"],
  "since_minutes": 1440
}
```

Display `error_log.tail_entries` and `contents` only when
`contents_included=true`. The `contents` array is compatibility redline text.

```bash
curl -sS --user "1:<openclaw-secret-field-value>" \
  -H "Content-Type: application/json" \
  -d '{"ability_id":"npcink-abilities-toolkit/wp-ops-diagnostics-detail","input":{"include_active_plugins":false,"include_inactive_plugins":false,"include_plugin_updates":false,"include_must_use_plugins":false,"include_dropins":false,"include_log_contents":false}}' \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/run-read-ability"
```

```bash
curl -sS --user "1:<openclaw-secret-field-value>" \
  -H "Content-Type: application/json" \
  -d '{"ability_id":"npcink-abilities-toolkit/wp-diagnostics-summary","input":{}}' \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/run-read-ability"
```

All P0/P1/P2 diagnostics detail shortcuts call
`npcink-abilities-toolkit/wp-ops-diagnostics-detail`. Do not use
`wp-diagnostics-summary` to decide whether plugin details, user permissions, or
log details are missing. Default inactive plugin rows are not missing; they are
default not requested. Adapter does not add Npcink runtime, MCP, or cloud
state to the WordPress diagnostics mapping.

Content context reads:

```bash
curl -sS --user "1:<openclaw-secret-field-value>" \
  -H "Content-Type: application/json" \
  -d '{"ability_id":"npcink-abilities-toolkit/list-posts","input":{"author_id":1,"orderby":"modified","order":"desc"}}' \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/run-read-ability"
```

```bash
curl -sS --user "1:<openclaw-secret-field-value>" \
  -H "Content-Type: application/json" \
  -d '{"ability_id":"npcink-abilities-toolkit/list-terms","input":{"taxonomy":"category","include_sample_posts":true,"sample_post_limit":3}}' \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/run-read-ability"
```

```bash
curl -sS --user "1:<openclaw-secret-field-value>" \
  -H "Content-Type: application/json" \
  -d '{"ability_id":"npcink-abilities-toolkit/get-menu","input":{"location":"primary"}}' \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/run-read-ability"
```

```bash
curl -sS --user "1:<openclaw-secret-field-value>" \
  -H "Content-Type: application/json" \
  -d '{"ability_id":"npcink-abilities-toolkit/list-media","input":{"per_page":1}}' \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/run-read-ability"
```

```bash
curl -sS --user "1:<openclaw-secret-field-value>" \
  -H "Content-Type: application/json" \
  -d '{"ability_id":"npcink-abilities-toolkit/list-pages","input":{"per_page":1}}' \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/run-read-ability"
```

If a route returns `npcink_openclaw_adapter_proposal_required`, stop and use the
proposal flow instead of trying to execute the ability directly.

Diagnostics shortcuts are aliases over `npcink-abilities-toolkit` direct-read
abilities. Adapter does not read arbitrary files, inspect database tables
directly, or own capability policy. It does own a bounded read envelope and
read-result redaction layer for rows where Core reports
`direct_read_sensitive` or `redaction_required=true`; those responses include
`read_policy`, `sensitivity`, `redaction_applied`, `redaction_summary`,
`read_audit_mode`, `correlation_id`, and `commit_execution=false`.

## Proposal-Required Write Flow

1. Call `GET /capabilities`.
2. Select a real `ability_id` where Core reports
   `governance_mode=proposal_required`.
3. Create a proposal:

```bash
curl -sS --user "1:<openclaw-secret-field-value>" \
  -H "Content-Type: application/json" \
  -d '{"ability_id":"npcink-abilities-toolkit/create-draft","title":"Draft proposal","summary":"OpenClaw requests a governed draft proposal.","input":{"title":"Local OpenClaw draft","dry_run":true,"commit":false},"preview":{},"caller":{"external_thread_id":"OPENCLAW_THREAD_ID"}}' \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/proposals"
```

4. Query proposal status through the adapter:

```bash
curl -sS --user "1:<openclaw-secret-field-value>" \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/proposals/PROPOSAL_ID"
```

For a list view:

```bash
curl -sS --user "1:<openclaw-secret-field-value>" \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/proposals?limit=10"
```

These are read-only Core status proxies. They preserve proposal fields such as
`proposal_id`, `ability_id`, `status`, `title`, `summary`, `input`, `preview`,
`caller`, `created_at`, `updated_at`, and detail `audit_timeline` when Core
returns it.

5. If `status=pending`, wait for a human to approve the proposal through
   `Npcink -> Core`, then call `POST /proposals/{proposal_id}/execute` for
   supported execution. The unified approve-and-execute route is reserved for
   WordPress administrator sessions and rejects signed clients with
   `npcink_openclaw_adapter_approve_requires_admin_session`.
   Do not call Core directly from OpenClaw.
6. If `status=rejected`, stop and show the rejection state or reason returned
   by Core.
7. If `status=approved` and execution is intended, call Adapter execute:

```bash
curl -sS --user "1:<openclaw-secret-field-value>" \
  -X POST \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/proposals/PROPOSAL_ID/execute"
```

8. Use Adapter commit-preflight only as an advanced diagnostic route. When it
   succeeds through Adapter, Adapter caches the one-time Core handoff for the
   next Adapter execute call. Dry-run-only verification stops at Adapter commit-preflight; do not call execute when the operator only asked for proposal/preflight validation. Do not call Core commit-preflight directly from OpenClaw.
9. For approved proposal execution, only `npcink-abilities-toolkit/trash-post`,
   `npcink-abilities-toolkit/create-draft`, `npcink-abilities-toolkit/update-post`,
   `npcink-abilities-toolkit/set-post-seo-meta`, `npcink-abilities-toolkit/set-post-slug`,
   `npcink-abilities-toolkit/set-post-terms`, `npcink-abilities-toolkit/delete-term`,
   `npcink-abilities-toolkit/update-media-details`, `npcink-abilities-toolkit/optimize-media-asset`,
   `npcink-abilities-toolkit/replace-media-file`,
   `npcink-abilities-toolkit/restore-media-backup`,
   `npcink-abilities-toolkit/adopt-cloud-media-derivative`,
   `npcink-abilities-toolkit/rename-media-file`,
   `npcink-abilities-toolkit/delete-media-permanently`,
   `npcink-abilities-toolkit/reply-comment`, `npcink-abilities-toolkit/trash-comment`, and
   `npcink-abilities-toolkit/approve-comment` are currently supported. The
   preferred operator path is one Adapter unified action from a WordPress
   administrator session (cookie, Application Password, or basic auth):

```bash
curl -sS --user "1:<openclaw-secret-field-value>" \
  -X POST \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/proposals/PROPOSAL_ID/approve-and-execute"
```

Adapter calls Core approve when the proposal is pending, calls Core
commit-preflight, verifies `approval_commit_authorized=true` and
`commit_execution=false`, then executes one WordPress Abilities API call.
Signed key-pair clients cannot call this route
(`npcink_openclaw_adapter_approve_requires_admin_session`); their path is a
human approval in the Core admin followed by
`POST /proposals/{proposal_id}/execute`.
Adapter execute is a final write path and normalizes ability input to `dry_run=false` and `commit=true`. Core remains the governance backend for
proposal state, approval, preflight, and audit.

For a batch plan-shaped proposal, the same route accepts
`input.write_actions[]` only when every action targets the Adapter execution
supported profiles and passes ability-specific input checks. Adapter still calls Core
approve and Core commit-preflight before running the bounded batch, and returns
per-action `results[]` with `execution_mode=batch_write_actions`.
10. The lower-level execution route is available only for already approved
   proposals:

```bash
curl -sS --user "1:<openclaw-secret-field-value>" \
  -X POST \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/proposals/PROPOSAL_ID/execute"
```

Adapter fetches the Core proposal, consumes a cached Adapter preflight handoff
when one was issued through Adapter, otherwise runs Core commit-preflight,
requires `approval_commit_authorized=true`, requires `commit_execution=false`,
passes Core `approval_context`, normalizes ability input to `dry_run=false` and `commit=true`,
and executes one proposal through WordPress Abilities API. The adapter does not
create its own governance state.
For abilities outside that execution supported profiles, Adapter does not execute final
WordPress writes.
Future execution abilities must be added one by one to the Adapter supported profiles
with dedicated smoke coverage; this is not a generic proxy-execute surface.

## Log Correlation

When OpenClaw has a Core proposal or preflight correlation id, pass it to
Adapter on later read or execution requests:

```bash
curl -sS --user "1:<openclaw-secret-field-value>" \
  -H "Content-Type: application/json" \
  -d '{"ability_id":"npcink-abilities-toolkit/site-info","input":{},"log_context":{"proposal_id":"PROPOSAL_ID","correlation_id":"CORRELATION_ID"}}' \
  "https://npcink.local/wp-json/npcink-openclaw-adapter/v1/run-read-ability"
```

For `POST /run-read-ability`, send the same values in a top-level
`log_context` object. Adapter copies these values into AI Request Logs context
through `wpai_request_log_context`; it does not merge AI Request Logs with Core
audit, and it does not forward those reserved fields as ability input.

Core Governance Audit is the governance log. WordPress `ai` plugin AI Request
Logs are the provider request log. Client `log_context` may carry only
`proposal_id`, `correlation_id`, `external_thread_id`, `openclaw_thread_id`,
`adapter_request_id`, and `adapter_route`. Adapter derives `ability_id`,
`governance_source=npcink-governance-core`, `via=npcink-ai-client-adapter`, and nested
`npcink_governance_core` identifiers into AI Request Logs context. It does not put
provider credentials, prompts, responses, token details, or AI Request Logs into
Core.
AI Request Logs are the provider request log.

The same annotation allowlist applies to proposal and sensitive-read caller
metadata. Client values cannot override Adapter's caller type, transport,
ability id, governance source, or authenticated signed-client fingerprint.
Log context is capped at 32 fields, two nested array levels, 200 bytes per
string, and approximately 8 KiB serialized, with secret-bearing keys removed.

Provider log correlation is verified only when a downstream AI client, Cloud
runtime, or provider integration emits an AI Request Logs row under Adapter
context. Adapter does not expose a provider/model smoke endpoint. Open AI
Request Logs and search the same `proposal_id` or `correlation_id`; if the
provider column is blank, use the Adapter context fields for the explicit
`ai_provider` and `ai_model` recorded by the downstream provider integration.

Approval and rejection endpoints are visible only as generic approval proxy routes:

```text
POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/approve
POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/reject
```

They return HTTP 403 with
`code=npcink_openclaw_adapter_execute_profile_unsupported`,
`core_proxy_execute=false`, and
`approval_surface=npcink_governance_core_admin`. Use
`POST /proposals/{proposal_id}/approve-and-execute` from a WordPress
administrator session for the Adapter unified user action, Npcink Governance
Core admin for split approval decisions, then
`POST /proposals/{proposal_id}/execute`. Adapter does
not forward the standalone stub calls to Core and OpenClaw does not get generic
approval power.

Failure code handling:

- `npcink_openclaw_adapter_execute_profile_unsupported`: approve through the
  Core admin (or the administrator-session unified action), then execute.
- `npcink_openclaw_adapter_approve_requires_admin_session`: a signed client
  tried the unified action; wait for human approval in the Core admin, then
  call `POST /proposals/{proposal_id}/execute`.
- `npcink_openclaw_adapter_execute_profile_unsupported`: stop; the proposal ability
  is outside Adapter's execution supported profiles.
- `npcink_openclaw_adapter_proposal_rejected`: stop and show the Core rejection.
- `npcink_openclaw_adapter_preflight_not_authorized` or
  `npcink_openclaw_adapter_preflight_item_blocked`: stop and show Core preflight
  details.

For `from-plan`, rejected proposal, and preflight-blocked failures, prefer the
additive `data.operator_feedback` object when present. It contains
`status`, `severity`, `message`, `reasons[]`, `revision_fields[]`,
`next_steps[]`, `can_retry_after_revision`, and `core_evidence`. OpenClaw
should show this to the operator, revise the source plan or draft, and create a
new proposal. It must not retry `approve-and-execute` against a rejected or
preflight-blocked proposal id.

If a direct Core app token is used for Core-side integration tests or a future
trusted handoff, the key must include `proposals:read` for list/detail status.
Do not put Core tokens in logs, proposal payloads, error responses, or docs
examples.

Adapter may also be configured with a Core app token only through the
`NPCINK_OPENCLAW_ADAPTER_CORE_APP_TOKEN` constant or environment variable. It
is not read from a WordPress option. This is Adapter internal
configuration only; do not put the raw token into OpenClaw prompts, proposal
payloads, screenshots, or handoff examples.
