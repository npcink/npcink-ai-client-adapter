# Adapter Boundary

Status: accepted architecture boundary.

Npcink AI Client Adapter is the thin OpenClaw channel layer for WordPress. It
owns the OpenClaw-facing REST namespace and the local client handoff surface. It
does not own ability definitions, governance truth, workflow runtime, provider
routing, prompt management, or generic WordPress write authority.

## Owned Here

Adapter owns:

- OpenClaw-facing REST routes under `npcink-openclaw-adapter/v1`.
- WordPress REST authentication and capability checks for the Adapter surface.
- Direct-read routing from OpenClaw clients to the WordPress Abilities API when
  Core marks the capability as executable by Adapter.
- Proposal creation, proposal status projection, and commit-preflight handoff
  to Npcink Governance Core.
- Explicit post-Core execution profile policy for approved writes.
- Small health, help, manifest, diagnostics, and client handoff responses.

## Not Owned Here

Adapter must not own:

- WordPress ability definitions, schemas, callbacks, or dry-run previews. Those
  belong in `npcink-abilities-toolkit`.
- Proposal storage, approval state, audit truth, read-grant truth, or
  commit-preflight truth. Those belong in `npcink-governance-core`.
- Workflow runtime, queues, MCP runtime, Agent Gateway catalogs, or long-running
  task orchestration.
- Provider credentials, model routing, prompt catalogs, or product UX.
- Cloud settings, Cloud connector routes, Cloud signing clients, or Cloud
  execution truth. Adapter calls the standalone Cloud Addon seam only where a
  documented local bridge requires it.

## Runtime Flow

Read operations:

```text
OpenClaw -> Adapter -> WordPress Abilities API
```

Governed write operations:

```text
OpenClaw -> Adapter -> Governance Core proposal/preflight -> Adapter execution profile -> WordPress Abilities API
```

The required boundary flags remain:

```text
core_proxy_execute=false
commit_execution=false
```

`commit_execution=false` means Core is not a generic execution proxy. Adapter
may still perform an explicitly profiled final write after Core approval,
commit-preflight, and operator commit intent.

## Runtime Contract Handshake

Adapter declares its own contract metadata on `GET /health`, `GET /help`, and
`GET /connection/manifest`. It also consumes bounded runtime contract summaries
from:

- `/npcink-governance-core/v1/contract`
- `/npcink-abilities-toolkit/v1/contract`

These dependency summaries are compatibility proofs, not authority transfer.
They must not copy Core proposal bodies, approval records, audit timelines,
ability callback internals, provider credentials, or raw secrets.
For Toolkit specifically, Adapter checks bounded official Abilities API
alignment evidence such as catalog source, schema source, callback-free stable
hashes, host-governed write controls, and forbidden payload omission. Adapter
does not copy Toolkit ability definitions, annotations, callbacks, permission
callables, dry-run previews, or raw schemas into its own registry.

Client-key fingerprint binding is intentionally cross-plugin. Adapter forwards
the authenticated local client key fingerprint to trusted Core app-token
requests. When Core emits `signed_client_fingerprint` or the compatible
`client_key_fingerprint` alias in preflight or sensitive-read authorization
contexts, Adapter verifies it against the current signed local client alongside
the existing `site_url`, `home_url`, and `blog_id` bindings.

## Source Documents

- [`../openclaw-adapter-contract.md`](../openclaw-adapter-contract.md)
- [`../cloud-connector-boundary.md`](../cloud-connector-boundary.md)
- [`../archive/2026-06-17-adapter-release-acceptance.md`](../archive/2026-06-17-adapter-release-acceptance.md)
