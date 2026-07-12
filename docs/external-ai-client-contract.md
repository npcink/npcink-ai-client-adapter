# External AI Client Contract

Status: accepted Adapter client integration contract.

This is the generic Npcink AI-client contract. OpenClaw is the first and priority implementation;
OpenClaw-compatible clients such as WorkBuddy, Qclaw,
and other local AI clients can use the same stable WordPress channel.

## Positioning

The client connects to `npcink-ai-client-adapter` only. Adapter is the thin
channel layer between the client, Npcink Governance Core, and WordPress
Abilities API.

The client must not treat Adapter as:

- a second Governance Core API;
- a second WordPress Abilities registry;
- a provider/model runtime;
- a prompt-management surface;
- a Cloud connector;
- a workflow runtime, queue, scheduler, or Agent Gateway catalog;
- a generic final write executor.

## Required Startup Sequence

1. Read `GET /health`.
2. Read `GET /help`.
3. Read `GET /connection/manifest`.
4. Read `GET /capabilities`.
5. Cache contract fingerprints only for drift detection:
   `adapter_contract_version`, `client_policy_version`,
   `execution_profile_registry_hash`, `supported_execute_ability_ids_hash`, and
   `supported_plan_ability_ids_hash`.
6. Read `contract.workflow_projection`, then discover canonical definitions
   through the Toolkit `list-workflow-recipes` or `get-workflow-recipe`
   abilities. Fail closed when the definition version or required parity fields
   do not match the client projection.

Fingerprints do not authorize reads or writes. Runtime authority still comes
from Core approval, Core read authorization, Core commit-preflight, and Adapter
execution profile validation.

Adapter must not persist a second workflow definition catalog. A projected
client action preserves Toolkit's `recipe_id`, `contract_version`, entrypoint,
scope, required inputs, handoff, failure policy, and host-governed write
boundary. OpenClaw-specific route names are transport compatibility details.

## Reads

Use `POST /run-read-ability` with a real `ability_id` from `/capabilities`.

The client must not call direct-read shortcut routes. It should pass only
bounded ability input plus optional `log_context` fields such as
`proposal_id`, `correlation_id`, `external_thread_id`,
`openclaw_thread_id`, `adapter_request_id`, and `adapter_route`.

For sensitive reads, use the read request flow:

1. `POST /read-requests`.
2. Wait for Core authorization.
3. `POST /run-read-ability` with `read_request_id`.

## Governed Writes

All WordPress mutations must flow through Core governance:

```text
client -> Adapter -> Core proposal -> Core approval -> Core commit-preflight -> Adapter execution profile -> WordPress Abilities API
```

The client may create proposals through:

- `POST /proposals`;
- `POST /proposals/from-plan`.

The client may execute only after Core approval and Core commit-preflight, and
only when Adapter exposes an execution profile for that ability id. Unsupported
proposal abilities must fail closed. Clients must create a revised proposal
instead of retrying blocked execution.

The client must not assume:

- Core can proxy execute;
- Adapter can commit without Core preflight;
- arbitrary `ability_id` values can execute writes;
- `input.write_actions[]` can contain unprofiled actions;
- preview, dry-run, or `commit=false` output is authorization to write.

## Cloud And Media Derivatives

Adapter does not own Cloud run creation, run/result lookup, artifact previews,
artifact registries, derivative payload builders, Cloud settings, Cloud
signing, or `/cloud/*` routes.

If a flow needs Cloud runtime, the Cloud Addon or Cloud tooling owns that
transport. Adapter may consume only bounded Cloud Addon readiness or
proposal-specific evidence, and may execute the explicit post-Core
`npcink-abilities-toolkit/adopt-cloud-media-derivative` profile after Core
approval and commit-preflight.

## Provider And Prompt Runtime

Adapter does not expose provider/model smoke routes and does not own provider
credentials, model routing, prompts, prompt execution, token accounting, or
product UX.

Provider request logs may include Adapter/Core correlation context when a
downstream AI client, Cloud runtime, or provider integration performs a real
provider call. Adapter itself only carries bounded context.

## Correlation Context

When a request belongs to a proposal, preflight, or external AI thread, pass the
stable identifiers through query fields or `log_context`:

```text
proposal_id
correlation_id
external_thread_id
openclaw_thread_id
adapter_request_id
adapter_route
governance_source=npcink-governance-core
```

Adapter must not forward reserved correlation fields as ability input.

## Client Failure Rules

The client should surface `data.operator_feedback` when present.

On blocked proposal creation, blocked preflight, rejected proposal, unsupported
ability, stale input hash, site/client binding mismatch, or execution profile
validation failure, the client should stop and create a revised proposal. It
must not bypass Core, call WordPress directly, or downgrade into an Adapter
generic write path.
