# OpenClaw Adapter Consumer Readiness

Status: complete
Date: 2026-06-02
Re-synced: 2026-10-11 against `npcink-ai-client-adapter` 0.4.1 (route surface
and execution supported profiles refreshed; the original June dependency
snapshot below is kept for history).

## Dependency Snapshot

- `npcink-governance-core` `master`: `6acb159 Add read governance metadata to capabilities`
- `npcink-openclaw-adapter` `master`: `b81dc2a Add read governance envelopes and redaction`
- `npcink-abilities-toolkit` local checkout: `2ee47a7 Fix catalog observability event throttling`

The snapshot records the local checkout state used for the post-governance
OpenClaw consumer acceptance pass.

## Product Entry

Productized OpenClaw only connects to Adapter:

```text
OpenClaw -> npcink-openclaw-adapter -> npcink-governance-core governance
OpenClaw -> npcink-openclaw-adapter -> WordPress Abilities API reads/execution
```

Core is the governance authority behind Adapter. Core owns proposal state,
approval/rejection truth, commit-preflight, and governance audit. Abilities own
schemas, callbacks, permissions, and direct-read execution.

Adapter owns the OpenClaw-facing REST channel and the bounded orchestration
needed to connect OpenClaw to Core governance and WordPress Abilities API.

## Verified Routes

The current Adapter route surface (re-synced to 0.4.1):

- `GET /health`
- `GET /help`
- `GET /capabilities`
- `GET /connection/manifest`
- `POST /connect/device/start`
- `POST /connect/device/poll`
- `GET /connection/key-pairs`
- `DELETE /connection/key-pairs/{key_id}` (administrator sessions only)
- `POST /run-read-ability`
- `GET /read-requests`, `POST /read-requests`,
  `GET /read-requests/{request_id}`
- `POST /proposals`, `GET /proposals`,
  `GET /proposals/{proposal_id}`
- `POST /proposals/from-plan`
- `POST /execute-approved-proposal`
- `POST /proposals/{proposal_id}/execute`
- `GET /proposals/{proposal_id}/media-optimization-readiness`
- `POST /proposals/{proposal_id}/approve-and-execute`
  (administrator sessions only)
- `POST /proposals/{proposal_id}/commit-preflight`

Direct-read shortcut routes, diagnostics shortcut routes, and standalone
`POST /proposals/{proposal_id}/approve` /
`POST /proposals/{proposal_id}/reject` stubs from earlier snapshots are
unregistered; calling those paths returns the standard WordPress REST
`rest_no_route` 404.

## Verified Loop

The readiness pass verified:

- capabilities discovery;
- direct read;
- diagnostics read;
- proposal create, list, and detail;
- human Core approval followed by Adapter execute (the unified `approve-and-execute`
  action is administrator-session only);
- Core commit-preflight before execution;
- supported final execution for `npcink-abilities-toolkit/trash-post`,
  `npcink-abilities-toolkit/create-draft`,
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
  `npcink-abilities-toolkit/reply-comment`,
  `npcink-abilities-toolkit/trash-comment`, and
  `npcink-abilities-toolkit/approve-comment`;
- rejected proposals do not execute;
- preflight-blocked proposals do not execute;
- non-supported proposals do not execute;
- AI Request Logs and Core Governance Audit correlation by `proposal_id` and
  `correlation_id`;
- returned `proposal_id`, `correlation_id`, `ability_id`, and
  `adapter_request_id` on the execution path.

The equivalent HTTP client pass used a temporary WordPress Application Password
and connected to Adapter for the OpenClaw-facing flow. The Core Audit lookup in
that pass was verification-only and is not part of the OpenClaw runtime
connection contract.

## Boundary

Adapter remains a thin OpenClaw channel layer:

- Adapter is not an MCP runtime.
- Adapter is not a workflow runtime.
- Adapter does not own Core proposal or audit truth.
- Adapter does not store provider credentials.
- Adapter does not generically execute arbitrary write abilities.
- Adapter does own the OpenClaw read envelope and bounded read-result redaction
  layer for direct-read rows where Core reports sensitive policy guidance.
- Adapter execution supported profiles currently includes `npcink-abilities-toolkit/trash-post`,
  `npcink-abilities-toolkit/create-draft`,
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
  `npcink-abilities-toolkit/reply-comment`,
  `npcink-abilities-toolkit/trash-comment`, and
  `npcink-abilities-toolkit/approve-comment`.

Standalone proposal approve/reject routes are not registered; calling those
paths returns the standard WordPress REST `rest_no_route` 404. Generic
proposal approval proxying must remain disabled unless a future decision
explicitly changes the product boundary. The current productized user action is
`POST /proposals/{proposal_id}/approve-and-execute`, reserved for WordPress
administrator sessions; signed clients use human Core approval followed by
`POST /proposals/{proposal_id}/execute`.

Batch `write_actions[]` execution is governed by
[`openclaw-batch-execution-policy.md`](openclaw-batch-execution-policy.md).
The policy keeps the execution supported profiles limited to explicitly implemented
ability ids.

## Next-Stage Rules

Do not default-expand the final execution supported profiles.

Each new Adapter-executable write ability needs its own ADR or execution policy
document before implementation. That document must define:

- ability id;
- input fields;
- idempotency behavior, including Adapter's completed execution record and
  `npcink_openclaw_adapter_execution_already_completed` replay rejection;
- failure handling;
- rollback or compensation behavior;
- log fields;
- Core preflight conditions;
- smoke coverage.

Core must not add a final execution route. Core remains the governance
authority, while Adapter remains the OpenClaw product entry and WordPress
execution channel for explicitly supported actions.
