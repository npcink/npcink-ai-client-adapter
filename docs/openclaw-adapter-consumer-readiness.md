# OpenClaw Adapter Consumer Readiness

Status: complete
Date: 2026-06-02

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

The consumer readiness pass verified these Adapter routes:

- `GET /health`
- `GET /help`
- `GET /capabilities`
- direct read shortcuts
- diagnostics shortcuts
- `POST /proposals`
- `GET /proposals`
- `GET /proposals/{proposal_id}`
- `POST /proposals/{proposal_id}/commit-preflight`
- `POST /proposals/{proposal_id}/approve-and-execute` (administrator sessions only)
- `POST /proposals/{proposal_id}/approve` and
  `POST /proposals/{proposal_id}/reject` as disabled HTTP 403 stubs

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
  `npcink-abilities-toolkit/create-draft`, `npcink-abilities-toolkit/update-post`,
  `npcink-abilities-toolkit/set-post-seo-meta`, `npcink-abilities-toolkit/set-post-slug`,
  `npcink-abilities-toolkit/set-post-terms`, `npcink-abilities-toolkit/delete-term`,
  `npcink-abilities-toolkit/update-media-details`, `npcink-abilities-toolkit/optimize-media-asset`,
  `npcink-abilities-toolkit/upload-media-from-url`,
  `npcink-abilities-toolkit/set-post-featured-image`, `npcink-abilities-toolkit/replace-media-file`,
  `npcink-abilities-toolkit/restore-media-backup`,
  `npcink-abilities-toolkit/adopt-cloud-media-derivative`,
  `npcink-abilities-toolkit/rename-media-file`,
  `npcink-abilities-toolkit/delete-media-permanently`,
  `npcink-abilities-toolkit/reply-comment`, `npcink-abilities-toolkit/trash-comment`, and
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
  `npcink-abilities-toolkit/approve-comment`.

The generic proposal approval proxying must remain disabled unless a future decision
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
