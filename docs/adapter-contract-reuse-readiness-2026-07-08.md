# Adapter Contract Reuse Readiness - 2026-07-08

Status: active observation record

This record closes the Adapter observation pass after Governance Core confirmed
`proposal_handoff` readiness and Abilities Toolkit confirmed `ability_contracts`
readiness. The purpose is to decide whether Adapter needs new implementation
work before the next project optimization pass.

## Scope

Adapter's role in the current reuse stack is `execution_profiles`:

- expose a small OpenClaw-compatible REST channel;
- execute direct reads through the WordPress Abilities API only when Core
  capability guidance allows that path;
- send write and destructive intents to Governance Core proposal and
  commit-preflight routes;
- execute only explicit post-Core final-write profiles after approval,
  commit-preflight, and operator commit intent;
- validate provider `implementation_posture` evidence before final execution
  when Core exposes it;
- summarize Core and Toolkit dependency contracts as bounded compatibility
  evidence on `/health`, `/help`, and `/connection/manifest`;
- record execution outcomes back to Core instead of becoming the audit truth
  owner.

The adjacent roles stay outside Adapter:

| Role | Owner |
| --- | --- |
| `ability_contracts` | `npcink-abilities-toolkit` or another WordPress Abilities API provider |
| `proposal_handoff` | `npcink-governance-core` |
| `product_surface` | `npcink-workflow-toolbox` or another product plugin |
| `signed_transport` | `npcink-cloud-addon` |
| `runtime_detail` | `npcink-ai-cloud` |

## Current Evidence

The current Adapter already has the hooks needed to receive reused contracts:

- `/health`, `/help`, and `/connection/manifest` expose stable contract hashes,
  `core_proxy_execute=false`, `commit_execution=false`, and
  `execution_handoff_posture`;
- `dependency_contracts` reads Core and Toolkit contract endpoints and carries
  only bounded compatibility fields, booleans, versions, and hashes;
- Core contract readiness checks require site binding, signed client
  fingerprint binding, `implementation_posture` support, and
  `provider_secret_storage=false`;
- Toolkit contract readiness checks require WordPress Abilities API catalog
  ownership, callback-free stable hashes, dry-run-first write controls,
  host-governed final commit ownership, and omission of forbidden payloads;
- `Execution_Profile_Registry` binds final execution to literal
  `npcink-abilities-toolkit/<ability>` ids with per-profile supported input
  fields, required fields, enums, and object-shape checks;
- final execution validates Core approval context, Core execution handoff,
  Core commit-preflight evidence, site binding, signed client fingerprint
  binding, idempotency, and target ability profile support;
- execution-time `implementation_posture_evidence` rejects posture drift,
  non-host-governed write posture, direct-write defaults, workflow runtime,
  queues, model routing, provider credentials, approval storage, or audit
  storage ownership;
- failed or completed execution outcomes are recorded back to Core rather than
  stored as Adapter governance truth.

## Active Observation Result

No new Adapter route, execution profile, or runtime code is needed for this pass.

The current contract surface is sufficient for AI clients and product plugins
to reuse Adapter as the channel and execution-profile layer. The important
follow-up is not to broaden Adapter execution, but to keep future product work
inside the existing handoff discipline:

```text
AI client or product intent
-> Adapter channel
-> Core capability guidance and Toolkit ability id
-> Core proposal or read authorization when required
-> Core commit-preflight for writes
-> Adapter explicit execution profile after operator commit intent
-> WordPress Abilities API execution
-> Core record-execution or failure evidence
```

## Representative Ready Contracts

These existing Adapter contracts are enough to continue the reuse pass:

- `execution_handoff_posture`
- `dependency_contracts`
- `implementation_posture_evidence`
- `Execution_Profile_Registry`
- `Supported_Plan_Abilities`
- `POST /run-read-ability`
- `POST /proposals`
- `POST /proposals/from-plan`
- `POST /proposals/{proposal_id}/commit-preflight`
- `POST /execute-approved-proposal`
- `POST /proposals/{proposal_id}/approve-and-execute`

Treat these as the reference channel contracts for host reuse. Add a new
execution profile only when a real Core, Toolkit, Toolbox, Cloud Addon, or
client proof fails because an existing explicit profile cannot express the
approved final WordPress write.

## Stop Rule

Stop and write a boundary note or ADR before implementing if a follow-up
requires Adapter to own any of these:

- WordPress ability definitions, schemas, callbacks, or dry-run previews;
- Core proposal records, approval lifecycle, commit-preflight truth, read-grant
  truth, or audit truth;
- generic final write authority, arbitrary ability id execution, wildcard
  execution profiles, or dynamic profile extension points;
- workflow runtime, task queues, retry workers, leases, schedulers, or batch
  execution consoles;
- product workflow UX, market onboarding, commercial packaging, or
  site-owner-specific flows;
- model routing, prompt/preset truth, provider credentials, quota, billing, or
  Cloud execution truth;
- MCP runtime, Agent Gateway catalogs, or OpenClaw projection truth beyond the
  documented Adapter contract;
- signed transport, Cloud connector routes, Cloud settings, or Site Knowledge
  lifecycle.

## Next Development Recommendation

End this Adapter observation pass here.

The next useful development slice should move to the product surface in the
reuse chain, not add new Adapter functionality. A good next slice is
`npcink-workflow-toolbox`: verify that the product surface uses real Toolkit or
Toolbox ability ids, displays Core/Adapter execution posture honestly, and
keeps fixed workflows as proposal or local-consent handoffs instead of silent
WordPress writes.

## Verification

Required Adapter gate for this record:

```bash
composer test:all
```

Run `composer smoke:wp` only if a future change touches real WordPress
activation, REST routing, Core proposal/preflight integration, WordPress
Abilities API execution, execution profiles, or Local.app smoke assumptions.
