# Cloud Connector Boundary

Status: active guidance
Date: 2026-05-30

## Purpose

Npcink OpenClaw Adapter is the OpenClaw channel layer. It is not the WordPress-side
Cloud connector.

Cloud runtime access, Cloud API key storage, request signing, entitlement
reads, observability upload, and hosted-runtime transport belong to the
standalone `npcink-cloud-addon`. Adapter may use that addon through its
public PHP seam when OpenClaw needs a Cloud-backed operation, but Adapter must
not grow its own Cloud settings, signing client, `/cloud/*` REST namespace, or
Cloud execution truth.

## Project Roles

| Project | Owns | Does not own |
| --- | --- | --- |
| `npcink-abilities-toolkit` | Canonical WordPress ability definitions, schemas, callbacks, permissions, dry-run previews, and read-only workflow recipe metadata. | Cloud calls, model routing, queues, billing, quota, approval state, audit truth, workflow runtime, or final writes. |
| `npcink-governance-core` | Governance, ability intake, proposal records, approval/rejection, commit preflight, scoped app keys, rate limits, and audit records. | Ability definitions, cloud execution, task queues, model routing, product workflows, or final write execution. |
| `npcink-openclaw-adapter` | OpenClaw-facing REST routes, non-secret WordPress connection manifest, read ability execution through WordPress Abilities API, Core proposal/preflight proxying, one supported approve-and-execute orchestration path, and optional calls into the Cloud Addon seam. | Cloud settings, Cloud API key storage, Cloud request signing, `/cloud/*` routes, ability registry, approval store, workflow runtime, durable queue, model router, provider credentials, Cloud analytics truth, generic approve/reject proxying, or final write authority. |
| `npcink-cloud-addon` | Cloud Base URL/API key settings, signed hosted runtime transport, run/result reads, entitlement and stats projections, media derivative transport helpers, and opt-in metadata-only plugin observability upload. | OpenClaw product UX, Core governance truth, local ability truth, approval truth, WordPress writes, prompt/router/preset control, scheduler truth, workflow/task queue control, or billing truth. |
| `npcink-cloud` | Hosted runtime, Cloud API, worker execution, run status, provider telemetry, usage/stats, health, entitlement, quota, diagnostics, and Cloud-side analysis generation. | WordPress control plane, local ability truth, local approval truth, OpenClaw projection truth, or WordPress writes. |

## Recommended Cloud Flow

```text
OpenClaw
  -> npcink-openclaw-adapter
      -> npcink-governance-core        // governance, approval, audit, preflight
      -> npcink-abilities-toolkit   // local WordPress data and ability callbacks
      -> npcink-cloud-addon // signed Cloud transport seam
          -> npcink-cloud   // hosted execution, stats, analysis, workers
```

The adapter is the local entry point for OpenClaw. Cloud Addon is the WordPress-side Cloud connector. Cloud remains the hosted execution and analysis
service. Core remains the governance authority. Abilities remain the canonical
local capability and callback source.

## Adapter Responsibilities

Adapter may add bounded Cloud Addon integration code for:

- detecting whether `npcink-cloud-addon` is active;
- calling `npcink_cloud_addon_get_connection_state()` for readiness and a
  scenario-specific public helper exposed by the addon;
- returning bounded Cloud Addon readiness or proposal-specific projections to
  OpenClaw when a governed user action explicitly needs Cloud evidence;
- carrying `proposal_id`, `correlation_id`, `external_thread_id`, and
  `openclaw_thread_id` across WordPress, Core, Cloud, and AI Request Logs;
- translating local WordPress context from Abilities into a Cloud request
  payload only when the operation is read-only, proposal evidence, or already
  approved by Core.

Adapter must keep these calls thin. It delegates Cloud credentials, signing,
endpoint supported profiles, durable execution, retry, queueing, analytics, and
Cloud-side projections to `npcink-cloud-addon` and `npcink-cloud`.

Adapter must not register Adapter-owned `/cloud/*` routes unless a future ADR
explicitly moves Cloud connector ownership back from Cloud Addon.

## Cloud Responsibilities

Cloud owns:

- durable hosted runs and run status;
- worker-backed execution;
- provider routing and provider-call telemetry;
- usage metering, stats rollups, health, diagnostics, quota, and entitlement;
- Cloud analysis generation and Cloud-owned result storage;
- Cloud service-plane operations and internal operator diagnostics.

Cloud must not directly write WordPress. Any Cloud result that implies a
WordPress mutation must return a draft, recommendation, report, pending change,
or proposal input for local review.

## Governance Rules

1. Read-only requests may flow:

   ```text
   OpenClaw -> Adapter -> Abilities -> Adapter -> Cloud Addon -> Cloud
   ```

   Use this for context gathering, stats, diagnostics, and analysis inputs that
   do not mutate WordPress.

2. Write or destructive requests must flow:

   ```text
   OpenClaw -> Adapter -> Core proposal -> Core approval/preflight -> Adapter -> Cloud Addon or local host
   ```

   Adapter must not bypass Core approval for any WordPress mutation.

3. Cloud-generated write recommendations must stop as reviewable artifacts:

   - proposal input;
   - dry-run preview;
   - report;
   - pending change;
   - structured recommendation.

4. Final WordPress writes remain local and governed. Adapter may execute only a
   narrow supported ability after Core approval and Core commit-preflight
   through the explicit approve-and-execute path. Adapter must not become a
   generic final write executor unless a future ADR explicitly changes that
   boundary.

## Adapter Cloud Addon Seam

Adapter may consume only the Cloud Addon public seam. The Adapter-safe seam is
limited to readiness, diagnostics, proposal evidence, and approved execution
support. Current Adapter-safe examples include:

- `npcink_cloud_addon_get_connection_state()`;
- scenario-specific Cloud Addon helpers for the bounded operation being
  projected (for example, runtime detail, feedback, or media transport);
- `npcink_cloud_addon_is_configured()`;
- `npcink_cloud_addon_receive_media_derivative_artifact()` for
  proposal-specific readiness checks and approved local adoption only. The
  Addon seam must return the exact verified receive contract: artifact bytes,
  bounded facts, transfer evidence, and the transfer-only delivery ACK.
  Adapter readiness accepts only the exact local 11-field artifact descriptor
  with canonical `artifact_id` and strict UTC RFC3339 expiry; legacy id aliases,
  impossible dates, and non-UTC offsets fail closed.

Adapter must not expose a parallel `/cloud/*` REST surface or duplicate Cloud
Addon settings. If OpenClaw needs Cloud health, run status, results, stats,
entitlement, or observability detail, Adapter should link the operator to Cloud
Addon or return only a bounded proposal-specific projection obtained through
Cloud Addon.

For media derivatives, Adapter must not expose Cloud façade routes such as
`/media-derivative-runs`, artifact preview proxy routes, or derivative
proposal-payload builders. Cloud Addon and Cloud tooling own run creation,
run/result lookup, artifact preview, artifact registry, payload builders, and
artifact transport. Adapter may only expose proposal-specific readiness and
execute the explicit post-Core `adopt-cloud-media-derivative` profile after
Core approval and commit preflight.

## Forbidden Adapter Shapes

Do not add these to Adapter:

- local durable workflow runtime;
- local task queue, retry engine, scheduler, or lease manager;
- Adapter-owned Cloud settings, signing clients, or `/cloud/*` routes;
- Cloud task execution truth;
- Cloud analytics truth;
- ability registry or fallback ability definitions;
- approval or rejection authority;
- final WordPress write execution;
- provider credential storage or model routing policy;
- prompt, preset, router, MCP, or Agent Gateway control plane truth.

## Next Implementation Sequence

1. Detect Cloud Addon:
   - check for the relevant public functions;
   - fail closed with clear operator guidance when the addon is missing or
     unverified;
   - do not read Cloud credentials from Adapter.

2. Add Cloud-backed OpenClaw behavior only through Cloud Addon:
   - collect local context through existing Abilities routes;
   - call the matching Cloud Addon scenario helper;
   - return Cloud `run_id`, status, result, or proposal input as a projection;
   - do not write WordPress.

3. Add governed write handoff only after read-only proof works:
   - Cloud returns recommendation/proposal input;
   - Adapter submits or relays to Core proposal flow;
   - Core approval/preflight remains mandatory before any write.

4. Add tests for boundary invariants:
   - Adapter has no queue or scheduler truth;
   - Adapter does not register `/cloud/*` routes;
   - Adapter does not store Cloud API keys or sign Cloud requests;
   - Cloud Addon calls do not expose standalone approve/reject proxying;
   - Cloud Addon calls do not execute final writes outside Adapter's
     Core-approved supported path;
   - write-like Cloud outputs require Core proposal/preflight handoff.

## Decision Summary

Adapter is not the WordPress-to-Cloud connector. It is responsible for the
OpenClaw channel, request shaping, Core/Abilities delegation, and correlation.
Cloud Addon owns local Cloud transport and signing. Adapter is not responsible
for Cloud execution truth, local governance truth, ability truth, or WordPress
write truth.
